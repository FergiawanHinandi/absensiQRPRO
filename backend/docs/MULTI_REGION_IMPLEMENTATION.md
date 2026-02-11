# 🌍 Multi-Region Implementation Guide

**Cloud Architect Tier-1**  
**Date**: 2026-02-10

---

## 📋 Implementation Roadmap

### Phase 1: Active-Passive (Months 1-3)

**Week 1-2: Infrastructure Setup**
- [ ] Set up Region B infrastructure
- [ ] Configure RDS read replica
- [ ] Configure Redis replication
- [ ] Set up S3 cross-region replication

**Week 3-4: Application Deployment**
- [ ] Deploy Laravel app to Region B
- [ ] Configure environment variables
- [ ] Test database connectivity
- [ ] Test Redis connectivity

**Week 5-6: Failover Configuration**
- [ ] Configure Route 53 health checks
- [ ] Set up failover routing
- [ ] Create failover runbook
- [ ] Test manual failover

**Week 7-8: Automation & Testing**
- [ ] Automate failover process
- [ ] Test automatic failover
- [ ] Load testing
- [ ] Documentation

**Week 9-12: Monitoring & Optimization**
- [ ] Set up monitoring dashboards
- [ ] Configure alerts
- [ ] Optimize costs
- [ ] Team training

---

### Phase 2: Active-Active (Months 4-6)

**Month 4: Global Load Balancer**
- [ ] Set up AWS Global Accelerator
- [ ] Configure geo-routing
- [ ] Implement sticky sessions
- [ ] Test routing logic

**Month 5: Database Multi-Master**
- [ ] Set up MySQL Group Replication
- [ ] Configure write routing
- [ ] Test data consistency
- [ ] Monitor replication lag

**Month 6: Optimization & Launch**
- [ ] Performance optimization
- [ ] Cost optimization
- [ ] Final testing
- [ ] Production launch

---

## 🛠️ Terraform Infrastructure

### Region A (Primary)

**File**: `terraform/region-a/main.tf`

```hcl
# Region A: us-east-1 (Primary)

provider "aws" {
  region = "us-east-1"
  alias  = "primary"
}

# VPC
resource "aws_vpc" "primary" {
  provider   = aws.primary
  cidr_block = "10.0.0.0/16"
  
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name        = "attendance-vpc-primary"
    Environment = "production"
    Region      = "us-east-1"
  }
}

# Subnets
resource "aws_subnet" "primary_public_a" {
  provider          = aws.primary
  vpc_id            = aws_vpc.primary.id
  cidr_block        = "10.0.1.0/24"
  availability_zone = "us-east-1a"

  tags = {
    Name = "attendance-public-a"
  }
}

resource "aws_subnet" "primary_public_b" {
  provider          = aws.primary
  vpc_id            = aws_vpc.primary.id
  cidr_block        = "10.0.2.0/24"
  availability_zone = "us-east-1b"

  tags = {
    Name = "attendance-public-b"
  }
}

resource "aws_subnet" "primary_private_a" {
  provider          = aws.primary
  vpc_id            = aws_vpc.primary.id
  cidr_block        = "10.0.10.0/24"
  availability_zone = "us-east-1a"

  tags = {
    Name = "attendance-private-a"
  }
}

resource "aws_subnet" "primary_private_b" {
  provider          = aws.primary
  vpc_id            = aws_vpc.primary.id
  cidr_block        = "10.0.11.0/24"
  availability_zone = "us-east-1b"

  tags = {
    Name = "attendance-private-b"
  }
}

# RDS MySQL (Primary)
resource "aws_db_instance" "primary" {
  provider = aws.primary
  
  identifier     = "attendance-db-primary"
  engine         = "mysql"
  engine_version = "8.0"
  instance_class = "db.t3.large"
  
  allocated_storage     = 100
  max_allocated_storage = 1000
  storage_type          = "gp3"
  storage_encrypted     = true
  
  db_name  = "attendance"
  username = "admin"
  password = var.db_password
  
  multi_az               = true
  backup_retention_period = 7
  backup_window          = "03:00-04:00"
  maintenance_window     = "mon:04:00-mon:05:00"
  
  enabled_cloudwatch_logs_exports = ["error", "general", "slowquery"]
  
  db_subnet_group_name   = aws_db_subnet_group.primary.name
  vpc_security_group_ids = [aws_security_group.rds_primary.id]
  
  tags = {
    Name        = "attendance-db-primary"
    Environment = "production"
  }
}

# ElastiCache Redis (Primary)
resource "aws_elasticache_replication_group" "primary" {
  provider = aws.primary
  
  replication_group_id       = "attendance-redis-primary"
  replication_group_description = "Redis cluster for attendance system"
  
  engine         = "redis"
  engine_version = "7.0"
  node_type      = "cache.t3.medium"
  
  num_cache_clusters         = 2
  automatic_failover_enabled = true
  multi_az_enabled          = true
  
  subnet_group_name  = aws_elasticache_subnet_group.primary.name
  security_group_ids = [aws_security_group.redis_primary.id]
  
  at_rest_encryption_enabled = true
  transit_encryption_enabled = true
  
  tags = {
    Name        = "attendance-redis-primary"
    Environment = "production"
  }
}

# ECS Cluster
resource "aws_ecs_cluster" "primary" {
  provider = aws.primary
  name     = "attendance-cluster-primary"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = {
    Name        = "attendance-cluster-primary"
    Environment = "production"
  }
}

# ECS Service
resource "aws_ecs_service" "app_primary" {
  provider = aws.primary
  
  name            = "attendance-app"
  cluster         = aws_ecs_cluster.primary.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 2
  
  launch_type = "FARGATE"
  
  network_configuration {
    subnets          = [aws_subnet.primary_private_a.id, aws_subnet.primary_private_b.id]
    security_groups  = [aws_security_group.app_primary.id]
    assign_public_ip = false
  }
  
  load_balancer {
    target_group_arn = aws_lb_target_group.app_primary.arn
    container_name   = "laravel-app"
    container_port   = 80
  }
  
  depends_on = [aws_lb_listener.app_primary]
}

# Application Load Balancer
resource "aws_lb" "primary" {
  provider = aws.primary
  
  name               = "attendance-alb-primary"
  internal           = false
  load_balancer_type = "application"
  security_groups    = [aws_security_group.alb_primary.id]
  subnets            = [aws_subnet.primary_public_a.id, aws_subnet.primary_public_b.id]
  
  enable_deletion_protection = true
  enable_http2              = true
  
  tags = {
    Name        = "attendance-alb-primary"
    Environment = "production"
  }
}

# S3 Bucket (Primary)
resource "aws_s3_bucket" "primary" {
  provider = aws.primary
  bucket   = "attendance-storage-primary"

  tags = {
    Name        = "attendance-storage-primary"
    Environment = "production"
  }
}

# S3 Versioning
resource "aws_s3_bucket_versioning" "primary" {
  provider = aws.primary
  bucket   = aws_s3_bucket.primary.id

  versioning_configuration {
    status = "Enabled"
  }
}

# S3 Cross-Region Replication
resource "aws_s3_bucket_replication_configuration" "primary" {
  provider = aws.primary
  
  role   = aws_iam_role.replication.arn
  bucket = aws_s3_bucket.primary.id

  rule {
    id     = "replicate-to-secondary"
    status = "Enabled"

    destination {
      bucket        = aws_s3_bucket.secondary.arn
      storage_class = "STANDARD"
    }
  }
}
```

---

### Region B (Secondary)

**File**: `terraform/region-b/main.tf`

```hcl
# Region B: ap-southeast-1 (Secondary)

provider "aws" {
  region = "ap-southeast-1"
  alias  = "secondary"
}

# VPC
resource "aws_vpc" "secondary" {
  provider   = aws.secondary
  cidr_block = "10.1.0.0/16"
  
  enable_dns_hostnames = true
  enable_dns_support   = true

  tags = {
    Name        = "attendance-vpc-secondary"
    Environment = "production"
    Region      = "ap-southeast-1"
  }
}

# RDS Read Replica
resource "aws_db_instance" "secondary" {
  provider = aws.secondary
  
  identifier     = "attendance-db-secondary"
  replicate_source_db = aws_db_instance.primary.arn
  
  instance_class = "db.t3.large"
  
  backup_retention_period = 7
  
  vpc_security_group_ids = [aws_security_group.rds_secondary.id]
  
  tags = {
    Name        = "attendance-db-secondary"
    Environment = "production"
    Role        = "read-replica"
  }
}

# ElastiCache Redis (Replica)
resource "aws_elasticache_replication_group" "secondary" {
  provider = aws.secondary
  
  replication_group_id       = "attendance-redis-secondary"
  replication_group_description = "Redis replica for attendance system"
  
  engine         = "redis"
  engine_version = "7.0"
  node_type      = "cache.t3.medium"
  
  num_cache_clusters         = 1
  automatic_failover_enabled = false
  
  subnet_group_name  = aws_elasticache_subnet_group.secondary.name
  security_group_ids = [aws_security_group.redis_secondary.id]
  
  tags = {
    Name        = "attendance-redis-secondary"
    Environment = "production"
    Role        = "replica"
  }
}

# ECS Cluster (Standby)
resource "aws_ecs_cluster" "secondary" {
  provider = aws.secondary
  name     = "attendance-cluster-secondary"

  setting {
    name  = "containerInsights"
    value = "enabled"
  }

  tags = {
    Name        = "attendance-cluster-secondary"
    Environment = "production"
    Role        = "standby"
  }
}

# ECS Service (Minimal for cost)
resource "aws_ecs_service" "app_secondary" {
  provider = aws.secondary
  
  name            = "attendance-app"
  cluster         = aws_ecs_cluster.secondary.id
  task_definition = aws_ecs_task_definition.app.arn
  desired_count   = 1  # Minimal for standby
  
  launch_type = "FARGATE"
  
  network_configuration {
    subnets          = [aws_subnet.secondary_private_a.id]
    security_groups  = [aws_security_group.app_secondary.id]
    assign_public_ip = false
  }
  
  load_balancer {
    target_group_arn = aws_lb_target_group.app_secondary.arn
    container_name   = "laravel-app"
    container_port   = 80
  }
}

# S3 Bucket (Replica)
resource "aws_s3_bucket" "secondary" {
  provider = aws.secondary
  bucket   = "attendance-storage-secondary"

  tags = {
    Name        = "attendance-storage-secondary"
    Environment = "production"
    Role        = "replica"
  }
}
```

---

### Route 53 Failover

**File**: `terraform/route53/main.tf`

```hcl
# Route 53 Health Checks and Failover

# Health Check for Region A
resource "aws_route53_health_check" "primary" {
  fqdn              = aws_lb.primary.dns_name
  port              = 443
  type              = "HTTPS"
  resource_path     = "/health/system"
  failure_threshold = "3"
  request_interval  = "30"

  tags = {
    Name = "attendance-health-primary"
  }
}

# Health Check for Region B
resource "aws_route53_health_check" "secondary" {
  fqdn              = aws_lb.secondary.dns_name
  port              = 443
  type              = "HTTPS"
  resource_path     = "/health/system"
  failure_threshold = "3"
  request_interval  = "30"

  tags = {
    Name = "attendance-health-secondary"
  }
}

# Route 53 Hosted Zone
resource "aws_route53_zone" "main" {
  name = "absensiqr.com"
}

# Primary Record (Region A)
resource "aws_route53_record" "primary" {
  zone_id = aws_route53_zone.main.zone_id
  name    = "api.absensiqr.com"
  type    = "A"

  set_identifier = "primary"
  failover_routing_policy {
    type = "PRIMARY"
  }

  alias {
    name                   = aws_lb.primary.dns_name
    zone_id                = aws_lb.primary.zone_id
    evaluate_target_health = true
  }

  health_check_id = aws_route53_health_check.primary.id
}

# Secondary Record (Region B)
resource "aws_route53_record" "secondary" {
  zone_id = aws_route53_zone.main.zone_id
  name    = "api.absensiqr.com"
  type    = "A"

  set_identifier = "secondary"
  failover_routing_policy {
    type = "SECONDARY"
  }

  alias {
    name                   = aws_lb.secondary.dns_name
    zone_id                = aws_lb.secondary.zone_id
    evaluate_target_health = true
  }

  health_check_id = aws_route53_health_check.secondary.id
}
```

---

## 📝 Deployment Checklist

### Pre-Deployment
- [ ] Review architecture diagram
- [ ] Verify AWS account limits
- [ ] Set up IAM roles and policies
- [ ] Configure VPC peering (if needed)
- [ ] Prepare environment variables

### Infrastructure Deployment
- [ ] Deploy Region A infrastructure
- [ ] Deploy Region B infrastructure
- [ ] Configure database replication
- [ ] Configure Redis replication
- [ ] Set up S3 cross-region replication
- [ ] Configure Route 53 health checks

### Application Deployment
- [ ] Build Docker images
- [ ] Push to ECR (both regions)
- [ ] Deploy to Region A
- [ ] Deploy to Region B
- [ ] Verify health checks

### Testing
- [ ] Test manual failover
- [ ] Test automatic failover
- [ ] Test failback
- [ ] Load testing
- [ ] Disaster recovery drill

### Monitoring
- [ ] Set up CloudWatch dashboards
- [ ] Configure alerts
- [ ] Set up log aggregation
- [ ] Configure APM (Application Performance Monitoring)

---

**Status**: ✅ Ready for Implementation  
**Last Updated**: 2026-02-10  
**Version**: 1.0
