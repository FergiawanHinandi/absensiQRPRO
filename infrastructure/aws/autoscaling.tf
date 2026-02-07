##############################################################################
# AWS Auto Scaling Group Configuration for AbsensiQRPro
# Terraform configuration for horizontal auto-scaling
##############################################################################

terraform {
  required_providers {
    aws = {
      source  = "hashicorp/aws"
      version = "~> 5.0"
    }
  }
}

variable "environment" {
  default = "production"
}

variable "app_name" {
  default = "absensi-api"
}

variable "vpc_id" {
  description = "VPC ID"
}

variable "private_subnet_ids" {
  description = "Private subnet IDs for app servers"
  type        = list(string)
}

variable "alb_target_group_arn" {
  description = "ALB Target Group ARN"
}

##############################################################################
# Launch Template (Stateless Server Configuration)
##############################################################################
resource "aws_launch_template" "api" {
  name_prefix   = "${var.app_name}-"
  image_id      = data.aws_ami.ubuntu.id
  instance_type = "t3.medium"

  # IAM Instance Profile
  iam_instance_profile {
    name = aws_iam_instance_profile.api.name
  }

  # Network Configuration
  network_interfaces {
    associate_public_ip_address = false
    security_groups             = [aws_security_group.api.id]
    delete_on_termination       = true
  }

  # Block Device (ephemeral - stateless)
  block_device_mappings {
    device_name = "/dev/sda1"
    ebs {
      volume_size           = 20
      volume_type           = "gp3"
      delete_on_termination = true
      encrypted             = true
    }
  }

  # User Data - Bootstrap Script
  user_data = base64encode(templatefile("${path.module}/userdata.sh", {
    app_name    = var.app_name
    environment = var.environment
    # Stateless config - pull from Parameter Store
    ssm_prefix  = "/${var.environment}/${var.app_name}"
  }))

  # Metadata Options
  metadata_options {
    http_endpoint               = "enabled"
    http_tokens                 = "required"
    http_put_response_hop_limit = 1
  }

  tag_specifications {
    resource_type = "instance"
    tags = {
      Name        = "${var.app_name}-${var.environment}"
      Environment = var.environment
      ManagedBy   = "terraform"
    }
  }

  lifecycle {
    create_before_destroy = true
  }
}

##############################################################################
# Auto Scaling Group
##############################################################################
resource "aws_autoscaling_group" "api" {
  name                = "${var.app_name}-${var.environment}-asg"
  vpc_zone_identifier = var.private_subnet_ids
  
  # Instance Limits
  min_size         = 2
  max_size         = 6
  desired_capacity = 2

  # Health Check Configuration
  health_check_type         = "ELB"
  health_check_grace_period = 120
  default_cooldown          = 300  # 5 minutes

  # Launch Template
  launch_template {
    id      = aws_launch_template.api.id
    version = "$Latest"
  }

  # Target Group (Auto-register to Load Balancer)
  target_group_arns = [var.alb_target_group_arn]

  # Instance Refresh (Rolling Deployment)
  instance_refresh {
    strategy = "Rolling"
    preferences {
      min_healthy_percentage = 50
      instance_warmup        = 120
    }
  }

  # Termination Policies
  termination_policies = ["OldestInstance", "Default"]

  # Lifecycle Hooks for graceful shutdown
  initial_lifecycle_hook {
    name                 = "launch-hook"
    lifecycle_transition = "autoscaling:EC2_INSTANCE_LAUNCHING"
    default_result       = "CONTINUE"
    heartbeat_timeout    = 300
  }

  # Tags propagated to instances
  tag {
    key                 = "Name"
    value               = "${var.app_name}-${var.environment}"
    propagate_at_launch = true
  }

  tag {
    key                 = "Environment"
    value               = var.environment
    propagate_at_launch = true
  }

  tag {
    key                 = "AutoScaling"
    value               = "true"
    propagate_at_launch = true
  }

  lifecycle {
    create_before_destroy = true
  }
}

##############################################################################
# Scaling Policies
##############################################################################

# CPU-based Scaling (Target Tracking)
resource "aws_autoscaling_policy" "cpu_target_tracking" {
  name                   = "${var.app_name}-cpu-target-tracking"
  autoscaling_group_name = aws_autoscaling_group.api.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ASGAverageCPUUtilization"
    }
    target_value     = 70.0
    disable_scale_in = false
  }
}

# Request Count Scaling (Target Tracking)
resource "aws_autoscaling_policy" "request_count_target_tracking" {
  name                   = "${var.app_name}-request-count-tracking"
  autoscaling_group_name = aws_autoscaling_group.api.name
  policy_type            = "TargetTrackingScaling"

  target_tracking_configuration {
    predefined_metric_specification {
      predefined_metric_type = "ALBRequestCountPerTarget"
      resource_label         = "${aws_lb.api.arn_suffix}/${aws_lb_target_group.api.arn_suffix}"
    }
    target_value     = 100.0  # 100 requests per target
    disable_scale_in = false
  }
}

# Custom Metric: Response Time (Step Scaling)
resource "aws_autoscaling_policy" "response_time_scale_up" {
  name                   = "${var.app_name}-response-time-scale-up"
  autoscaling_group_name = aws_autoscaling_group.api.name
  policy_type            = "StepScaling"
  adjustment_type        = "ChangeInCapacity"

  step_adjustment {
    scaling_adjustment          = 1
    metric_interval_lower_bound = 0
    metric_interval_upper_bound = 500
  }

  step_adjustment {
    scaling_adjustment          = 2
    metric_interval_lower_bound = 500
  }
}

resource "aws_cloudwatch_metric_alarm" "response_time_high" {
  alarm_name          = "${var.app_name}-response-time-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 3
  metric_name         = "TargetResponseTime"
  namespace           = "AWS/ApplicationELB"
  period              = 60
  statistic           = "Average"
  threshold           = 0.5  # 500ms
  alarm_description   = "Response time exceeds 500ms"
  alarm_actions       = [aws_autoscaling_policy.response_time_scale_up.arn]

  dimensions = {
    LoadBalancer = aws_lb.api.arn_suffix
    TargetGroup  = aws_lb_target_group.api.arn_suffix
  }
}

# Custom Metric: Queue Backlog (Step Scaling)
resource "aws_autoscaling_policy" "queue_backlog_scale_up" {
  name                   = "${var.app_name}-queue-backlog-scale-up"
  autoscaling_group_name = aws_autoscaling_group.api.name
  policy_type            = "StepScaling"
  adjustment_type        = "ChangeInCapacity"

  step_adjustment {
    scaling_adjustment          = 2
    metric_interval_lower_bound = 0
  }
}

resource "aws_cloudwatch_metric_alarm" "queue_backlog_high" {
  alarm_name          = "${var.app_name}-queue-backlog-high"
  comparison_operator = "GreaterThanThreshold"
  evaluation_periods  = 2
  metric_name         = "QueueBacklog"
  namespace           = "AbsensiQRPro"
  period              = 60
  statistic           = "Average"
  threshold           = 500
  alarm_description   = "Queue backlog exceeds 500 jobs"
  alarm_actions       = [aws_autoscaling_policy.queue_backlog_scale_up.arn]
}

# Scale Down Policy
resource "aws_autoscaling_policy" "scale_down" {
  name                   = "${var.app_name}-scale-down"
  autoscaling_group_name = aws_autoscaling_group.api.name
  policy_type            = "SimpleScaling"
  adjustment_type        = "ChangeInCapacity"
  scaling_adjustment     = -1
  cooldown               = 300
}

resource "aws_cloudwatch_metric_alarm" "cpu_low" {
  alarm_name          = "${var.app_name}-cpu-low"
  comparison_operator = "LessThanThreshold"
  evaluation_periods  = 5
  metric_name         = "CPUUtilization"
  namespace           = "AWS/EC2"
  period              = 60
  statistic           = "Average"
  threshold           = 30
  alarm_description   = "CPU utilization below 30%"
  alarm_actions       = [aws_autoscaling_policy.scale_down.arn]

  dimensions = {
    AutoScalingGroupName = aws_autoscaling_group.api.name
  }
}

##############################################################################
# Scheduled Scaling (Predictive)
##############################################################################

# Scale up before school hours (6 AM Mon-Fri)
resource "aws_autoscaling_schedule" "school_hours_start" {
  scheduled_action_name  = "school-hours-start"
  autoscaling_group_name = aws_autoscaling_group.api.name
  min_size               = 4
  max_size               = 6
  desired_capacity       = 4
  recurrence             = "0 6 * * 1-5"  # 6 AM Mon-Fri
  time_zone              = "Asia/Jakarta"
}

# Peak hours (7 AM Mon-Fri)
resource "aws_autoscaling_schedule" "peak_hours" {
  scheduled_action_name  = "peak-hours"
  autoscaling_group_name = aws_autoscaling_group.api.name
  min_size               = 4
  max_size               = 6
  desired_capacity       = 6
  recurrence             = "0 7 * * 1-5"  # 7 AM Mon-Fri
  time_zone              = "Asia/Jakarta"
}

# Scale down after school (3 PM Mon-Fri)
resource "aws_autoscaling_schedule" "afternoon" {
  scheduled_action_name  = "afternoon"
  autoscaling_group_name = aws_autoscaling_group.api.name
  min_size               = 2
  max_size               = 6
  desired_capacity       = 3
  recurrence             = "0 15 * * 1-5"  # 3 PM Mon-Fri
  time_zone              = "Asia/Jakarta"
}

# Off hours (8 PM daily)
resource "aws_autoscaling_schedule" "off_hours" {
  scheduled_action_name  = "off-hours"
  autoscaling_group_name = aws_autoscaling_group.api.name
  min_size               = 2
  max_size               = 6
  desired_capacity       = 2
  recurrence             = "0 20 * * *"  # 8 PM daily
  time_zone              = "Asia/Jakarta"
}

##############################################################################
# SNS Notifications
##############################################################################
resource "aws_sns_topic" "autoscaling_notifications" {
  name = "${var.app_name}-autoscaling-notifications"
}

resource "aws_autoscaling_notification" "api_notifications" {
  group_names = [aws_autoscaling_group.api.name]
  notifications = [
    "autoscaling:EC2_INSTANCE_LAUNCH",
    "autoscaling:EC2_INSTANCE_TERMINATE",
    "autoscaling:EC2_INSTANCE_LAUNCH_ERROR",
    "autoscaling:EC2_INSTANCE_TERMINATE_ERROR",
  ]
  topic_arn = aws_sns_topic.autoscaling_notifications.arn
}

##############################################################################
# Security Group
##############################################################################
resource "aws_security_group" "api" {
  name        = "${var.app_name}-${var.environment}-sg"
  description = "Security group for API servers"
  vpc_id      = var.vpc_id

  ingress {
    description     = "HTTP from ALB"
    from_port       = 9000
    to_port         = 9000
    protocol        = "tcp"
    security_groups = [aws_security_group.alb.id]
  }

  egress {
    from_port   = 0
    to_port     = 0
    protocol    = "-1"
    cidr_blocks = ["0.0.0.0/0"]
  }

  tags = {
    Name        = "${var.app_name}-${var.environment}-sg"
    Environment = var.environment
  }
}

##############################################################################
# Outputs
##############################################################################
output "autoscaling_group_name" {
  value = aws_autoscaling_group.api.name
}

output "autoscaling_group_arn" {
  value = aws_autoscaling_group.api.arn
}

output "launch_template_id" {
  value = aws_launch_template.api.id
}
