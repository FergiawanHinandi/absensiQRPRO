##############################################################################
# GCP Managed Instance Group Configuration for AbsensiQRPro
# Terraform configuration for horizontal auto-scaling
##############################################################################

terraform {
  required_providers {
    google = {
      source  = "hashicorp/google"
      version = "~> 5.0"
    }
  }
}

variable "project_id" {
  description = "GCP Project ID"
}

variable "region" {
  default = "asia-southeast1"
}

variable "environment" {
  default = "production"
}

variable "app_name" {
  default = "absensi-api"
}

variable "network" {
  description = "VPC Network"
}

variable "subnetwork" {
  description = "Subnetwork for instances"
}

##############################################################################
# Instance Template (Stateless Server)
##############################################################################
resource "google_compute_instance_template" "api" {
  name_prefix  = "${var.app_name}-${var.environment}-"
  machine_type = "e2-medium"
  region       = var.region

  # Boot Disk
  disk {
    source_image = "ubuntu-os-cloud/ubuntu-2204-lts"
    auto_delete  = true
    boot         = true
    disk_size_gb = 20
    disk_type    = "pd-ssd"
  }

  # Network
  network_interface {
    network    = var.network
    subnetwork = var.subnetwork
    # No external IP - uses Cloud NAT for outbound
  }

  # Service Account
  service_account {
    email  = google_service_account.api.email
    scopes = ["cloud-platform"]
  }

  # Metadata & Startup Script
  metadata = {
    startup-script = templatefile("${path.module}/startup-script.sh", {
      app_name    = var.app_name
      environment = var.environment
      project_id  = var.project_id
    })
  }

  # Labels
  labels = {
    app         = var.app_name
    environment = var.environment
    managed-by  = "terraform"
  }

  # Shielded VM
  shielded_instance_config {
    enable_secure_boot          = true
    enable_vtpm                 = true
    enable_integrity_monitoring = true
  }

  lifecycle {
    create_before_destroy = true
  }
}

##############################################################################
# Health Check
##############################################################################
resource "google_compute_health_check" "api" {
  name                = "${var.app_name}-${var.environment}-health-check"
  check_interval_sec  = 10
  timeout_sec         = 5
  healthy_threshold   = 2
  unhealthy_threshold = 3

  http_health_check {
    port         = 9000
    request_path = "/health/load-balancer"
  }
}

##############################################################################
# Managed Instance Group (Regional for HA)
##############################################################################
resource "google_compute_region_instance_group_manager" "api" {
  name               = "${var.app_name}-${var.environment}-mig"
  base_instance_name = "${var.app_name}-${var.environment}"
  region             = var.region

  # Distribution across zones
  distribution_policy_zones = [
    "${var.region}-a",
    "${var.region}-b",
    "${var.region}-c",
  ]

  # Instance Template
  version {
    instance_template = google_compute_instance_template.api.id
  }

  # Target Size (managed by autoscaler)
  target_size = 2

  # Named Ports (for Load Balancer)
  named_port {
    name = "http"
    port = 9000
  }

  # Auto Healing
  auto_healing_policies {
    health_check      = google_compute_health_check.api.id
    initial_delay_sec = 120
  }

  # Update Policy (Rolling)
  update_policy {
    type                           = "PROACTIVE"
    minimal_action                 = "REPLACE"
    most_disruptive_allowed_action = "REPLACE"
    max_surge_fixed                = 2
    max_unavailable_fixed          = 0
    min_ready_sec                  = 60
    replacement_method             = "SUBSTITUTE"
  }

  lifecycle {
    create_before_destroy = true
  }
}

##############################################################################
# Autoscaler
##############################################################################
resource "google_compute_region_autoscaler" "api" {
  name   = "${var.app_name}-${var.environment}-autoscaler"
  region = var.region
  target = google_compute_region_instance_group_manager.api.id

  autoscaling_policy {
    # Instance Limits
    min_replicas    = 2
    max_replicas    = 6
    cooldown_period = 300  # 5 minutes

    # CPU Utilization
    cpu_utilization {
      target = 0.70  # 70%
    }

    # Load Balancing Utilization
    load_balancing_utilization {
      target = 0.70
    }

    # Custom Metrics
    metric {
      name   = "custom.googleapis.com/${var.app_name}/response_time_ms"
      type   = "GAUGE"
      target = 500  # 500ms
    }

    metric {
      name   = "custom.googleapis.com/${var.app_name}/queue_backlog"
      type   = "GAUGE"
      target = 500  # 500 jobs
    }

    # Scale-in Controls
    scale_in_control {
      max_scaled_in_replicas {
        fixed = 1
      }
      time_window_sec = 300  # 5 minutes
    }
  }
}

##############################################################################
# Backend Service (Load Balancer)
##############################################################################
resource "google_compute_backend_service" "api" {
  name                  = "${var.app_name}-${var.environment}-backend"
  protocol              = "HTTP"
  port_name             = "http"
  timeout_sec           = 30
  load_balancing_scheme = "EXTERNAL_MANAGED"

  # Instance Group Backend
  backend {
    group           = google_compute_region_instance_group_manager.api.instance_group
    balancing_mode  = "UTILIZATION"
    max_utilization = 0.8
    capacity_scaler = 1.0
  }

  # Health Check
  health_checks = [google_compute_health_check.api.id]

  # Session Affinity (None for stateless)
  session_affinity = "NONE"

  # CDN (optional)
  enable_cdn = false

  # Logging
  log_config {
    enable      = true
    sample_rate = 0.5
  }
}

##############################################################################
# Cloud Scheduler for Scheduled Scaling
##############################################################################

# Scale up before school hours
resource "google_cloud_scheduler_job" "scale_up_morning" {
  name        = "${var.app_name}-scale-up-morning"
  description = "Scale up for school hours"
  schedule    = "0 6 * * 1-5"  # 6 AM Mon-Fri
  time_zone   = "Asia/Jakarta"

  http_target {
    uri         = "https://compute.googleapis.com/compute/v1/projects/${var.project_id}/regions/${var.region}/autoscalers/${google_compute_region_autoscaler.api.name}"
    http_method = "PATCH"
    body        = base64encode(jsonencode({
      autoscalingPolicy = {
        minNumReplicas = 4
      }
    }))
    oauth_token {
      service_account_email = google_service_account.scheduler.email
    }
  }
}

# Scale down for off hours
resource "google_cloud_scheduler_job" "scale_down_evening" {
  name        = "${var.app_name}-scale-down-evening"
  description = "Scale down for off hours"
  schedule    = "0 20 * * *"  # 8 PM daily
  time_zone   = "Asia/Jakarta"

  http_target {
    uri         = "https://compute.googleapis.com/compute/v1/projects/${var.project_id}/regions/${var.region}/autoscalers/${google_compute_region_autoscaler.api.name}"
    http_method = "PATCH"
    body        = base64encode(jsonencode({
      autoscalingPolicy = {
        minNumReplicas = 2
      }
    }))
    oauth_token {
      service_account_email = google_service_account.scheduler.email
    }
  }
}

##############################################################################
# Service Accounts
##############################################################################
resource "google_service_account" "api" {
  account_id   = "${var.app_name}-${var.environment}"
  display_name = "Service account for ${var.app_name} instances"
}

resource "google_service_account" "scheduler" {
  account_id   = "${var.app_name}-scheduler"
  display_name = "Service account for Cloud Scheduler"
}

# IAM Bindings
resource "google_project_iam_member" "api_secret_accessor" {
  project = var.project_id
  role    = "roles/secretmanager.secretAccessor"
  member  = "serviceAccount:${google_service_account.api.email}"
}

resource "google_project_iam_member" "scheduler_compute" {
  project = var.project_id
  role    = "roles/compute.admin"
  member  = "serviceAccount:${google_service_account.scheduler.email}"
}

##############################################################################
# Outputs
##############################################################################
output "instance_group" {
  value = google_compute_region_instance_group_manager.api.instance_group
}

output "autoscaler_name" {
  value = google_compute_region_autoscaler.api.name
}

output "backend_service" {
  value = google_compute_backend_service.api.id
}
