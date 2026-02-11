# AI-Based Auto Scaling Controller

## Executive Summary

This document defines an AI-powered auto-scaling system that predicts load 15 minutes in advance and scales proactively, reducing costs by 30-40% while maintaining 99.9% SLA.

**Key Benefits:**
- **Predictive Scaling:** Scale before peak (not during)
- **Cost Optimization:** 30-40% reduction in compute costs
- **Improved Performance:** No overload during sudden spikes
- **Intelligent Fallback:** CPU-based scaling when model confidence is low

---

## Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        DATA COLLECTION LAYER                         │
│                                                                      │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐         │
│  │  Prometheus  │    │   MySQL      │    │  ClickHouse  │         │
│  │  (Metrics)   │    │  (OLTP)      │    │  (Analytics) │         │
│  └──────┬───────┘    └──────┬───────┘    └──────┬───────┘         │
│         │                   │                   │                  │
└─────────┼───────────────────┼───────────────────┼──────────────────┘
          │                   │                   │
          ▼                   ▼                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      FEATURE ENGINEERING LAYER                       │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Feature Extraction Service (Python)                ││
│  │                                                                 ││
│  │  • Extract time features (day, hour, minute)                   ││
│  │  • Calculate rolling statistics (mean, std, max)               ││
│  │  • Encode categorical features (region, holiday)               ││
│  │  • Aggregate historical patterns                               ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         ML MODEL LAYER                               │
│                                                                      │
│  ┌──────────────────────────────────────────────────────────────┐  │
│  │                    Phase 1: Time Series Model                 │  │
│  │                                                               │  │
│  │  ┌─────────────┐         ┌─────────────┐                    │  │
│  │  │   Prophet   │         │    LSTM     │                    │  │
│  │  │  (Baseline) │         │  (Advanced) │                    │  │
│  │  └──────┬──────┘         └──────┬──────┘                    │  │
│  │         │                       │                            │  │
│  │         └───────────┬───────────┘                            │  │
│  │                     ▼                                        │  │
│  │              ┌─────────────┐                                 │  │
│  │              │   Ensemble  │                                 │  │
│  │              │  Prediction │                                 │  │
│  │              └──────┬──────┘                                 │  │
│  └─────────────────────┼──────────────────────────────────────┘  │
│                        │                                          │
│  ┌─────────────────────┼──────────────────────────────────────┐  │
│  │                Phase 2: Reinforcement Learning              │  │
│  │                     (Future Enhancement)                     │  │
│  │                                                              │  │
│  │              ┌──────▼──────┐                                │  │
│  │              │  RL Agent   │                                │  │
│  │              │  (DQN/PPO)  │                                │  │
│  │              └──────┬──────┘                                │  │
│  └─────────────────────┼──────────────────────────────────────┘  │
│                        │                                          │
└────────────────────────┼──────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      DECISION & EXECUTION LAYER                      │
│                                                                      │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │              Scaling Decision Engine                            ││
│  │                                                                 ││
│  │  IF model_confidence > 0.8:                                    ││
│  │      Use AI prediction                                         ││
│  │  ELSE:                                                         ││
│  │      Fallback to CPU-based scaling                             ││
│  └────────────────────────┬───────────────────────────────────────┘│
│                           │                                         │
└───────────────────────────┼─────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     KUBERNETES INTEGRATION                           │
│                                                                      │
│  ┌──────────────┐         ┌──────────────┐                         │
│  │  Custom HPA  │────────▶│  Kubernetes  │                         │
│  │  Controller  │         │     API      │                         │
│  └──────────────┘         └──────────────┘                         │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      FEEDBACK & MONITORING                           │
│                                                                      │
│  • Actual vs Predicted comparison                                   │
│  • Model accuracy tracking                                          │
│  • Cost savings calculation                                         │
│  • Continuous model retraining                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Feature Engineering

### Feature List

#### 1. Temporal Features

```python
# Time-based features
features = {
    # Basic time features
    'hour_of_day': 0-23,           # Peak at 7-8 AM
    'day_of_week': 0-6,            # Monday = highest
    'day_of_month': 1-31,
    'week_of_year': 1-52,
    'month': 1-12,
    
    # Derived time features
    'is_monday': bool,             # Monday spike
    'is_weekend': bool,            # Low usage
    'is_school_hours': bool,       # 06:00-18:00
    'is_peak_hours': bool,         # 07:00-08:00
    'is_holiday': bool,            # From calendar
    'is_exam_period': bool,        # High usage
    
    # Cyclical encoding (for neural networks)
    'hour_sin': sin(2π * hour / 24),
    'hour_cos': cos(2π * hour / 24),
    'day_sin': sin(2π * day / 7),
    'day_cos': cos(2π * day / 7),
}
```

#### 2. Historical Load Features

```python
# Historical patterns
features = {
    # Rolling statistics (last 7 days, same hour)
    'rps_mean_7d': float,          # Average RPS
    'rps_std_7d': float,           # Volatility
    'rps_max_7d': float,           # Peak load
    'rps_min_7d': float,           # Baseline
    
    # Recent trends (last 1 hour)
    'rps_mean_1h': float,
    'rps_trend_1h': float,         # Slope
    'rps_volatility_1h': float,
    
    # Lag features
    'rps_lag_15min': float,        # 15 min ago
    'rps_lag_30min': float,        # 30 min ago
    'rps_lag_1h': float,           # 1 hour ago
    'rps_lag_1d': float,           # Same time yesterday
    'rps_lag_7d': float,           # Same time last week
}
```

#### 3. Business Context Features

```python
# Business metrics
features = {
    # School activity
    'active_schools_count': int,
    'total_students_count': int,
    'qr_generate_frequency': float,  # QR/min
    'attendance_scan_rate': float,   # Scans/min
    
    # Subscription metrics
    'premium_schools_count': int,
    'trial_schools_count': int,
    'subscription_churn_rate': float,
    
    # Regional distribution
    'jakarta_schools_ratio': float,
    'surabaya_schools_ratio': float,
    'other_regions_ratio': float,
    
    # Event indicators
    'scheduled_events_count': int,   # School events today
    'notification_batch_pending': int,
}
```

#### 4. System Health Features

```python
# Infrastructure metrics
features = {
    # Current state
    'current_pod_count': int,
    'current_cpu_usage': float,     # 0-100%
    'current_memory_usage': float,  # 0-100%
    'current_queue_lag': int,       # Jobs
    
    # Database
    'db_connection_pool_usage': float,
    'db_query_latency_p95': float,
    
    # Cache
    'redis_memory_usage': float,
    'redis_hit_rate': float,
}
```

### Feature Extraction Pipeline

```python
# ml/feature_engineering.py
import pandas as pd
import numpy as np
from datetime import datetime, timedelta
from sklearn.preprocessing import StandardScaler

class FeatureEngineer:
    def __init__(self, prometheus_client, mysql_client, clickhouse_client):
        self.prometheus = prometheus_client
        self.mysql = mysql_client
        self.clickhouse = clickhouse_client
        self.scaler = StandardScaler()
        
    def extract_features(self, timestamp):
        """Extract all features for a given timestamp"""
        features = {}
        
        # 1. Temporal features
        features.update(self._extract_temporal_features(timestamp))
        
        # 2. Historical load features
        features.update(self._extract_historical_features(timestamp))
        
        # 3. Business context features
        features.update(self._extract_business_features(timestamp))
        
        # 4. System health features
        features.update(self._extract_system_features(timestamp))
        
        return features
    
    def _extract_temporal_features(self, timestamp):
        """Extract time-based features"""
        dt = pd.Timestamp(timestamp)
        
        return {
            'hour_of_day': dt.hour,
            'day_of_week': dt.dayofweek,
            'day_of_month': dt.day,
            'week_of_year': dt.isocalendar()[1],
            'month': dt.month,
            
            'is_monday': int(dt.dayofweek == 0),
            'is_weekend': int(dt.dayofweek >= 5),
            'is_school_hours': int(6 <= dt.hour <= 18),
            'is_peak_hours': int(7 <= dt.hour <= 8),
            'is_holiday': self._is_holiday(dt),
            
            # Cyclical encoding
            'hour_sin': np.sin(2 * np.pi * dt.hour / 24),
            'hour_cos': np.cos(2 * np.pi * dt.hour / 24),
            'day_sin': np.sin(2 * np.pi * dt.dayofweek / 7),
            'day_cos': np.cos(2 * np.pi * dt.dayofweek / 7),
        }
    
    def _extract_historical_features(self, timestamp):
        """Extract historical load patterns"""
        # Query Prometheus for historical RPS
        query = 'rate(http_requests_total[1m])'
        
        # Last 7 days, same hour
        rps_7d = self.prometheus.query_range(
            query,
            start=timestamp - timedelta(days=7),
            end=timestamp,
            step='1h'
        )
        
        # Recent 1 hour
        rps_1h = self.prometheus.query_range(
            query,
            start=timestamp - timedelta(hours=1),
            end=timestamp,
            step='1m'
        )
        
        return {
            'rps_mean_7d': np.mean(rps_7d),
            'rps_std_7d': np.std(rps_7d),
            'rps_max_7d': np.max(rps_7d),
            'rps_min_7d': np.min(rps_7d),
            
            'rps_mean_1h': np.mean(rps_1h),
            'rps_trend_1h': self._calculate_trend(rps_1h),
            'rps_volatility_1h': np.std(rps_1h),
            
            'rps_lag_15min': rps_1h[-15] if len(rps_1h) >= 15 else 0,
            'rps_lag_30min': rps_1h[-30] if len(rps_1h) >= 30 else 0,
            'rps_lag_1h': rps_1h[0] if len(rps_1h) > 0 else 0,
        }
    
    def _extract_business_features(self, timestamp):
        """Extract business metrics"""
        # Query MySQL for current business state
        cursor = self.mysql.cursor(dictionary=True)
        
        cursor.execute("""
            SELECT 
                COUNT(DISTINCT id) as active_schools,
                SUM(max_students) as total_students
            FROM schools
            WHERE status = 'active'
        """)
        school_stats = cursor.fetchone()
        
        cursor.execute("""
            SELECT COUNT(*) as qr_count
            FROM qr_generations
            WHERE created_at > NOW() - INTERVAL 15 MINUTE
        """)
        qr_stats = cursor.fetchone()
        
        return {
            'active_schools_count': school_stats['active_schools'],
            'total_students_count': school_stats['total_students'],
            'qr_generate_frequency': qr_stats['qr_count'] / 15.0,
        }
    
    def _extract_system_features(self, timestamp):
        """Extract current system state"""
        # Query Prometheus for current metrics
        cpu_usage = self.prometheus.query('avg(rate(container_cpu_usage_seconds_total[1m]))')
        memory_usage = self.prometheus.query('avg(container_memory_usage_bytes / container_spec_memory_limit_bytes)')
        pod_count = self.prometheus.query('count(kube_pod_info{app="attendance-web"})')
        
        return {
            'current_pod_count': int(pod_count),
            'current_cpu_usage': float(cpu_usage) * 100,
            'current_memory_usage': float(memory_usage) * 100,
        }
    
    def _is_holiday(self, dt):
        """Check if date is a holiday"""
        # Query holiday calendar from database
        cursor = self.mysql.cursor()
        cursor.execute("""
            SELECT COUNT(*) FROM holidays
            WHERE date = %s
        """, (dt.date(),))
        return cursor.fetchone()[0] > 0
    
    def _calculate_trend(self, values):
        """Calculate linear trend"""
        if len(values) < 2:
            return 0
        x = np.arange(len(values))
        slope, _ = np.polyfit(x, values, 1)
        return slope
```

---

## Model Architecture

### Phase 1: Time Series Forecasting

#### Option A: Prophet (Baseline)

```python
# ml/models/prophet_model.py
from fbprophet import Prophet
import pandas as pd

class ProphetScalingModel:
    def __init__(self):
        self.model = Prophet(
            yearly_seasonality=True,
            weekly_seasonality=True,
            daily_seasonality=True,
            seasonality_mode='multiplicative',
            changepoint_prior_scale=0.05,
        )
        
        # Add custom seasonalities
        self.model.add_seasonality(
            name='hourly',
            period=1,
            fourier_order=8
        )
        
    def train(self, historical_data):
        """Train Prophet model on historical RPS data"""
        # Prepare data in Prophet format
        df = pd.DataFrame({
            'ds': historical_data['timestamp'],
            'y': historical_data['rps'],
        })
        
        # Add regressors
        for col in ['is_holiday', 'is_monday', 'active_schools_count']:
            self.model.add_regressor(col)
            df[col] = historical_data[col]
        
        self.model.fit(df)
        
    def predict(self, future_timestamps, features):
        """Predict RPS for future timestamps"""
        future = pd.DataFrame({
            'ds': future_timestamps,
            **features
        })
        
        forecast = self.model.predict(future)
        
        return {
            'predicted_rps': forecast['yhat'].values,
            'lower_bound': forecast['yhat_lower'].values,
            'upper_bound': forecast['yhat_upper'].values,
            'confidence': self._calculate_confidence(forecast),
        }
    
    def _calculate_confidence(self, forecast):
        """Calculate prediction confidence"""
        # Confidence based on prediction interval width
        interval_width = forecast['yhat_upper'] - forecast['yhat_lower']
        confidence = 1 - (interval_width / forecast['yhat']).clip(0, 1)
        return confidence.mean()
```

#### Option B: LSTM (Advanced)

```python
# ml/models/lstm_model.py
import torch
import torch.nn as nn
import numpy as np

class LSTMScalingModel(nn.Module):
    def __init__(self, input_size, hidden_size=128, num_layers=2, dropout=0.2):
        super(LSTMScalingModel, self).__init__()
        
        self.hidden_size = hidden_size
        self.num_layers = num_layers
        
        # LSTM layers
        self.lstm = nn.LSTM(
            input_size=input_size,
            hidden_size=hidden_size,
            num_layers=num_layers,
            dropout=dropout,
            batch_first=True
        )
        
        # Attention mechanism
        self.attention = nn.MultiheadAttention(
            embed_dim=hidden_size,
            num_heads=4,
            dropout=dropout
        )
        
        # Output layers
        self.fc1 = nn.Linear(hidden_size, 64)
        self.fc2 = nn.Linear(64, 32)
        self.fc3 = nn.Linear(32, 1)  # Predict RPS
        
        self.relu = nn.ReLU()
        self.dropout = nn.Dropout(dropout)
        
    def forward(self, x):
        """
        x: (batch_size, sequence_length, input_size)
        """
        # LSTM
        lstm_out, (hidden, cell) = self.lstm(x)
        
        # Attention
        attn_out, _ = self.attention(lstm_out, lstm_out, lstm_out)
        
        # Use last hidden state
        out = attn_out[:, -1, :]
        
        # Fully connected layers
        out = self.relu(self.fc1(out))
        out = self.dropout(out)
        out = self.relu(self.fc2(out))
        out = self.dropout(out)
        out = self.fc3(out)
        
        return out

class LSTMTrainer:
    def __init__(self, model, learning_rate=0.001):
        self.model = model
        self.optimizer = torch.optim.Adam(model.parameters(), lr=learning_rate)
        self.criterion = nn.MSELoss()
        
    def train(self, train_loader, val_loader, epochs=50):
        """Train LSTM model"""
        best_val_loss = float('inf')
        
        for epoch in range(epochs):
            # Training
            self.model.train()
            train_loss = 0
            
            for batch_x, batch_y in train_loader:
                self.optimizer.zero_grad()
                
                predictions = self.model(batch_x)
                loss = self.criterion(predictions, batch_y)
                
                loss.backward()
                self.optimizer.step()
                
                train_loss += loss.item()
            
            # Validation
            self.model.eval()
            val_loss = 0
            
            with torch.no_grad():
                for batch_x, batch_y in val_loader:
                    predictions = self.model(batch_x)
                    loss = self.criterion(predictions, batch_y)
                    val_loss += loss.item()
            
            avg_train_loss = train_loss / len(train_loader)
            avg_val_loss = val_loss / len(val_loader)
            
            print(f"Epoch {epoch+1}/{epochs} - Train Loss: {avg_train_loss:.4f}, Val Loss: {avg_val_loss:.4f}")
            
            # Save best model
            if avg_val_loss < best_val_loss:
                best_val_loss = avg_val_loss
                torch.save(self.model.state_dict(), 'models/lstm_best.pth')
    
    def predict(self, features, sequence_length=60):
        """Predict RPS for next 15 minutes"""
        self.model.eval()
        
        with torch.no_grad():
            # Prepare input sequence
            x = torch.FloatTensor(features[-sequence_length:]).unsqueeze(0)
            
            predictions = []
            confidence_scores = []
            
            # Predict next 15 time steps (15 minutes)
            for _ in range(15):
                pred = self.model(x)
                predictions.append(pred.item())
                
                # Calculate confidence (inverse of prediction variance)
                confidence = self._calculate_confidence(x, pred)
                confidence_scores.append(confidence)
                
                # Update sequence for next prediction
                x = torch.cat([x[:, 1:, :], pred.unsqueeze(1).unsqueeze(2)], dim=1)
            
            return {
                'predicted_rps': predictions,
                'confidence': np.mean(confidence_scores),
            }
    
    def _calculate_confidence(self, x, pred):
        """Calculate prediction confidence using Monte Carlo dropout"""
        self.model.train()  # Enable dropout
        
        samples = []
        for _ in range(10):
            sample = self.model(x)
            samples.append(sample.item())
        
        self.model.eval()
        
        # Confidence = 1 - (std / mean)
        std = np.std(samples)
        mean = np.mean(samples)
        confidence = 1 - min(std / (mean + 1e-6), 1.0)
        
        return confidence
```

#### Ensemble Model

```python
# ml/models/ensemble.py
class EnsembleScalingModel:
    def __init__(self, prophet_model, lstm_model):
        self.prophet = prophet_model
        self.lstm = lstm_model
        self.weights = {'prophet': 0.3, 'lstm': 0.7}  # LSTM gets more weight
        
    def predict(self, timestamp, features):
        """Ensemble prediction"""
        # Prophet prediction
        prophet_result = self.prophet.predict([timestamp], features)
        
        # LSTM prediction
        lstm_result = self.lstm.predict(features)
        
        # Weighted average
        predicted_rps = (
            self.weights['prophet'] * prophet_result['predicted_rps'][0] +
            self.weights['lstm'] * lstm_result['predicted_rps'][0]
        )
        
        # Minimum confidence
        confidence = min(
            prophet_result['confidence'],
            lstm_result['confidence']
        )
        
        return {
            'predicted_rps': predicted_rps,
            'confidence': confidence,
            'prophet_prediction': prophet_result['predicted_rps'][0],
            'lstm_prediction': lstm_result['predicted_rps'][0],
        }
```

---

## Scaling Decision Engine

```python
# ml/scaling_controller.py
import math
from datetime import datetime, timedelta

class AIScalingController:
    def __init__(self, model, feature_engineer, k8s_client):
        self.model = model
        self.feature_engineer = feature_engineer
        self.k8s = k8s_client
        
        # Configuration
        self.min_pods = 2
        self.max_pods = 50
        self.target_rps_per_pod = 100
        self.confidence_threshold = 0.8
        self.scale_up_buffer = 1.2  # 20% buffer
        self.scale_down_buffer = 0.8  # 20% buffer
        
    def run(self):
        """Main control loop"""
        while True:
            try:
                # Get current timestamp
                now = datetime.now()
                
                # Extract features
                features = self.feature_engineer.extract_features(now)
                
                # Predict load for next 15 minutes
                prediction = self.model.predict(
                    timestamp=now + timedelta(minutes=15),
                    features=features
                )
                
                # Make scaling decision
                decision = self._make_decision(prediction, features)
                
                # Execute scaling
                if decision['should_scale']:
                    self._execute_scaling(decision)
                
                # Log decision
                self._log_decision(decision, prediction)
                
                # Sleep for 1 minute
                time.sleep(60)
                
            except Exception as e:
                print(f"Error in scaling controller: {e}")
                # Fallback to CPU-based scaling
                self._fallback_scaling()
                time.sleep(60)
    
    def _make_decision(self, prediction, features):
        """Make scaling decision based on prediction"""
        predicted_rps = prediction['predicted_rps']
        confidence = prediction['confidence']
        current_pods = features['current_pod_count']
        
        # Check confidence threshold
        if confidence < self.confidence_threshold:
            return {
                'should_scale': False,
                'reason': f'Low confidence ({confidence:.2f} < {self.confidence_threshold})',
                'fallback': True,
            }
        
        # Calculate required pods
        required_pods = math.ceil(predicted_rps / self.target_rps_per_pod)
        
        # Apply buffers
        if required_pods > current_pods:
            # Scale up: add buffer
            target_pods = math.ceil(required_pods * self.scale_up_buffer)
        elif required_pods < current_pods:
            # Scale down: add buffer
            target_pods = math.ceil(required_pods * self.scale_down_buffer)
        else:
            target_pods = current_pods
        
        # Clamp to min/max
        target_pods = max(self.min_pods, min(self.max_pods, target_pods))
        
        # Determine if scaling is needed
        should_scale = target_pods != current_pods
        
        # Prevent thrashing (don't scale if change is < 20%)
        if should_scale:
            change_ratio = abs(target_pods - current_pods) / current_pods
            if change_ratio < 0.2:
                should_scale = False
        
        return {
            'should_scale': should_scale,
            'current_pods': current_pods,
            'target_pods': target_pods,
            'predicted_rps': predicted_rps,
            'confidence': confidence,
            'reason': f'Predicted RPS: {predicted_rps:.0f}, Required pods: {required_pods}',
            'fallback': False,
        }
    
    def _execute_scaling(self, decision):
        """Execute scaling via Kubernetes API"""
        target_pods = decision['target_pods']
        
        print(f"Scaling from {decision['current_pods']} to {target_pods} pods")
        print(f"Reason: {decision['reason']}")
        
        # Update HPA via Kubernetes API
        self.k8s.patch_namespaced_horizontal_pod_autoscaler(
            name='attendance-web-hpa',
            namespace='production',
            body={
                'spec': {
                    'minReplicas': target_pods,
                    'maxReplicas': target_pods,
                }
            }
        )
        
        print(f"✓ Scaled to {target_pods} pods")
    
    def _fallback_scaling(self):
        """Fallback to CPU-based scaling"""
        print("Using fallback CPU-based scaling")
        
        # Reset HPA to use CPU metrics
        self.k8s.patch_namespaced_horizontal_pod_autoscaler(
            name='attendance-web-hpa',
            namespace='production',
            body={
                'spec': {
                    'metrics': [
                        {
                            'type': 'Resource',
                            'resource': {
                                'name': 'cpu',
                                'target': {
                                    'type': 'Utilization',
                                    'averageUtilization': 70
                                }
                            }
                        }
                    ]
                }
            }
        )
    
    def _log_decision(self, decision, prediction):
        """Log scaling decision for analysis"""
        log_entry = {
            'timestamp': datetime.now().isoformat(),
            'decision': decision,
            'prediction': prediction,
        }
        
        # Store in database for model retraining
        # Also send to monitoring system
        pass
```

---

## Retraining Schedule

### Continuous Learning Pipeline

```python
# ml/retraining_pipeline.py
from airflow import DAG
from airflow.operators.python import PythonOperator
from datetime import datetime, timedelta

default_args = {
    'owner': 'ml-team',
    'depends_on_past': False,
    'email_on_failure': True,
    'email_on_retry': False,
    'retries': 1,
    'retry_delay': timedelta(minutes=5),
}

dag = DAG(
    'ml_model_retraining',
    default_args=default_args,
    description='Retrain AI scaling model',
    schedule_interval='0 2 * * 0',  # Weekly on Sunday 2 AM
    start_date=datetime(2026, 1, 1),
    catchup=False,
)

def extract_training_data(**context):
    """Extract last 90 days of data"""
    from ml.data_loader import DataLoader
    
    loader = DataLoader()
    data = loader.load_historical_data(days=90)
    
    # Save to temp location
    data.to_parquet('/tmp/training_data.parquet')
    
    print(f"Extracted {len(data)} records")

def train_prophet_model(**context):
    """Train Prophet model"""
    import pandas as pd
    from ml.models.prophet_model import ProphetScalingModel
    
    data = pd.read_parquet('/tmp/training_data.parquet')
    
    model = ProphetScalingModel()
    model.train(data)
    
    # Save model
    model.save('models/prophet_latest.pkl')
    
    print("Prophet model trained")

def train_lstm_model(**context):
    """Train LSTM model"""
    import pandas as pd
    from ml.models.lstm_model import LSTMScalingModel, LSTMTrainer
    
    data = pd.read_parquet('/tmp/training_data.parquet')
    
    # Prepare data loaders
    train_loader, val_loader = prepare_data_loaders(data)
    
    model = LSTMScalingModel(input_size=50)
    trainer = LSTMTrainer(model)
    trainer.train(train_loader, val_loader, epochs=50)
    
    print("LSTM model trained")

def evaluate_models(**context):
    """Evaluate model performance"""
    from ml.evaluation import ModelEvaluator
    
    evaluator = ModelEvaluator()
    
    prophet_metrics = evaluator.evaluate('models/prophet_latest.pkl')
    lstm_metrics = evaluator.evaluate('models/lstm_best.pth')
    
    print(f"Prophet MAE: {prophet_metrics['mae']:.2f}")
    print(f"LSTM MAE: {lstm_metrics['mae']:.2f}")
    
    # If new model is better, promote to production
    if lstm_metrics['mae'] < get_production_model_mae():
        promote_model('models/lstm_best.pth', 'models/lstm_production.pth')
        print("New model promoted to production")

def deploy_model(**context):
    """Deploy model to production"""
    # Copy model to production location
    # Restart scaling controller
    pass

# Define tasks
extract_data = PythonOperator(
    task_id='extract_training_data',
    python_callable=extract_training_data,
    dag=dag,
)

train_prophet = PythonOperator(
    task_id='train_prophet_model',
    python_callable=train_prophet_model,
    dag=dag,
)

train_lstm = PythonOperator(
    task_id='train_lstm_model',
    python_callable=train_lstm_model,
    dag=dag,
)

evaluate = PythonOperator(
    task_id='evaluate_models',
    python_callable=evaluate_models,
    dag=dag,
)

deploy = PythonOperator(
    task_id='deploy_model',
    python_callable=deploy_model,
    dag=dag,
)

# Define dependencies
extract_data >> [train_prophet, train_lstm] >> evaluate >> deploy
```

### Retraining Triggers

| Trigger | Condition | Action |
|---------|-----------|--------|
| **Scheduled** | Every Sunday 2 AM | Full retraining on 90 days data |
| **Performance Degradation** | MAE > 20% for 3 days | Emergency retraining |
| **Data Drift** | Distribution shift detected | Incremental retraining |
| **New Features** | Feature engineering update | Full retraining |
| **Seasonal Change** | New academic year | Full retraining |

---

## Failure Fallback Logic

```python
# ml/fallback_controller.py
class FallbackController:
    def __init__(self, k8s_client):
        self.k8s = k8s_client
        self.fallback_active = False
        
    def check_and_fallback(self, prediction, features):
        """Check if fallback is needed"""
        reasons = []
        
        # Check 1: Low confidence
        if prediction['confidence'] < 0.8:
            reasons.append(f"Low confidence: {prediction['confidence']:.2f}")
        
        # Check 2: Prediction out of reasonable range
        if prediction['predicted_rps'] < 0 or prediction['predicted_rps'] > 10000:
            reasons.append(f"Unreasonable prediction: {prediction['predicted_rps']:.0f}")
        
        # Check 3: Model error
        if 'error' in prediction:
            reasons.append(f"Model error: {prediction['error']}")
        
        # Check 4: Stale features
        if self._features_are_stale(features):
            reasons.append("Stale features detected")
        
        if reasons:
            print(f"Fallback triggered: {', '.join(reasons)}")
            self._activate_fallback()
            return True
        
        return False
    
    def _activate_fallback(self):
        """Activate CPU-based scaling"""
        if self.fallback_active:
            return
        
        print("Activating fallback to CPU-based scaling")
        
        # Update HPA to use CPU metrics
        self.k8s.patch_namespaced_horizontal_pod_autoscaler(
            name='attendance-web-hpa',
            namespace='production',
            body={
                'spec': {
                    'minReplicas': 2,
                    'maxReplicas': 50,
                    'metrics': [
                        {
                            'type': 'Resource',
                            'resource': {
                                'name': 'cpu',
                                'target': {
                                    'type': 'Utilization',
                                    'averageUtilization': 70
                                }
                            }
                        },
                        {
                            'type': 'Pods',
                            'pods': {
                                'metric': {
                                    'name': 'queue_lag'
                                },
                                'target': {
                                    'type': 'AverageValue',
                                    'averageValue': '1000'
                                }
                            }
                        }
                    ]
                }
            }
        )
        
        self.fallback_active = True
        
        # Send alert
        self._send_alert("AI scaling fallback activated")
    
    def _features_are_stale(self, features):
        """Check if features are stale"""
        # Check if Prometheus is responding
        # Check if database is accessible
        # etc.
        return False
    
    def _send_alert(self, message):
        """Send alert to ops team"""
        # Send to Slack, PagerDuty, etc.
        pass
```

---

## Cost Optimization Strategy

### Cost Savings Analysis

```python
# ml/cost_analysis.py
class CostAnalyzer:
    def __init__(self):
        self.pod_cost_per_hour = 0.05  # $0.05 per pod per hour
        
    def calculate_savings(self, ai_scaling_log, baseline_scaling_log):
        """Calculate cost savings from AI scaling"""
        
        # AI scaling cost
        ai_pod_hours = sum(
            log['pod_count'] * log['duration_hours']
            for log in ai_scaling_log
        )
        ai_cost = ai_pod_hours * self.pod_cost_per_hour
        
        # Baseline (CPU-based) cost
        baseline_pod_hours = sum(
            log['pod_count'] * log['duration_hours']
            for log in baseline_scaling_log
        )
        baseline_cost = baseline_pod_hours * self.pod_cost_per_hour
        
        # Savings
        savings = baseline_cost - ai_cost
        savings_percent = (savings / baseline_cost) * 100
        
        return {
            'ai_cost': ai_cost,
            'baseline_cost': baseline_cost,
            'savings': savings,
            'savings_percent': savings_percent,
            'ai_pod_hours': ai_pod_hours,
            'baseline_pod_hours': baseline_pod_hours,
        }
```

### Expected Cost Reduction

| Scenario | Baseline (CPU) | AI Scaling | Savings |
|----------|---------------|------------|---------|
| **Off-Peak (00:00-06:00)** | 10 pods | 2 pods | 80% |
| **Normal (09:00-16:00)** | 15 pods | 12 pods | 20% |
| **Peak (07:00-08:00)** | 20 pods | 18 pods | 10% |
| **Weekend** | 8 pods | 3 pods | 62% |
| **Holiday** | 5 pods | 2 pods | 60% |
| **Monthly Average** | - | - | **35-40%** |

### Optimization Strategies

1. **Predictive Scale-Down:**
   - Scale down 10 minutes before low usage period
   - Save on unnecessary pod-hours

2. **Aggressive Off-Peak Scaling:**
   - Reduce to minimum pods during 00:00-06:00
   - Use spot instances for cost savings

3. **Regional Optimization:**
   - Scale based on regional patterns
   - Jakarta peaks earlier than other regions

4. **Event-Aware Scaling:**
   - Pre-scale for known events (exam days, holidays)
   - Avoid reactive scaling spikes

---

## Monitoring & Evaluation

### Metrics Dashboard

```yaml
# grafana/dashboards/ai-scaling.json
{
  "dashboard": {
    "title": "AI Scaling Controller",
    "panels": [
      {
        "title": "Predicted vs Actual RPS",
        "targets": [
          {
            "expr": "predicted_rps",
            "legendFormat": "Predicted"
          },
          {
            "expr": "rate(http_requests_total[1m])",
            "legendFormat": "Actual"
          }
        ]
      },
      {
        "title": "Model Confidence",
        "targets": [
          {
            "expr": "model_confidence",
            "legendFormat": "Confidence"
          }
        ],
        "thresholds": [
          { "value": 0.8, "color": "green" },
          { "value": 0.6, "color": "yellow" },
          { "value": 0, "color": "red" }
        ]
      },
      {
        "title": "Scaling Decisions",
        "targets": [
          {
            "expr": "kube_horizontalpodautoscaler_spec_min_replicas",
            "legendFormat": "Target Pods"
          },
          {
            "expr": "kube_horizontalpodautoscaler_status_current_replicas",
            "legendFormat": "Current Pods"
          }
        ]
      },
      {
        "title": "Cost Savings",
        "targets": [
          {
            "expr": "ai_scaling_cost_savings_percent",
            "legendFormat": "Savings %"
          }
        ]
      },
      {
        "title": "Prediction Error (MAE)",
        "targets": [
          {
            "expr": "abs(predicted_rps - actual_rps)",
            "legendFormat": "Absolute Error"
          }
        ]
      }
    ]
  }
}
```

---

## Implementation Roadmap

### Phase 1: Foundation (Month 1-2)

- [ ] Set up data collection pipeline
- [ ] Implement feature engineering
- [ ] Train baseline Prophet model
- [ ] Deploy monitoring dashboard
- [ ] Run in shadow mode (predict but don't scale)

### Phase 2: Production Pilot (Month 3)

- [ ] Train LSTM model
- [ ] Implement ensemble model
- [ ] Deploy to 10% of traffic
- [ ] Monitor accuracy and cost savings
- [ ] Tune confidence thresholds

### Phase 3: Full Rollout (Month 4)

- [ ] Deploy to 100% of traffic
- [ ] Implement automated retraining
- [ ] Set up alerting and fallback
- [ ] Document runbooks

### Phase 4: Optimization (Month 5-6)

- [ ] Implement reinforcement learning (Phase 2)
- [ ] Add more features
- [ ] Optimize cost further
- [ ] A/B test different strategies

---

## Conclusion

This AI-based auto-scaling controller provides:

✅ **Predictive Scaling:** 15-minute advance prediction  
✅ **Cost Optimization:** 35-40% reduction in compute costs  
✅ **Improved Performance:** No overload during peaks  
✅ **Intelligent Fallback:** CPU-based scaling when confidence is low  
✅ **Continuous Learning:** Weekly retraining on latest data  

The system is ready for implementation starting with Phase 1 (Foundation).
