# 🎯 RISK OVERVIEW DASHBOARD - JSON OUTPUT

## 📊 **AGGREGATION QUERIES YANG DIGUNAKAN**

### **1. Total Students by Risk Level Query**
```sql
WITH student_attendance AS (
    SELECT 
        u.id as student_id,
        u.name as student_name,
        cs.class_id,
        c.name as class_name,
        COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
        COUNT(a.id) as total_days,
        CASE 
            WHEN COUNT(a.id) = 0 THEN 0
            ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
        END as attendance_percentage
    FROM users u
    INNER JOIN class_students cs ON u.id = cs.student_id
    INNER JOIN classes c ON cs.class_id = c.id
    LEFT JOIN attendances a ON u.id = a.student_id 
        AND a.attendance_date >= ? -- 30 days ago
        AND a.school_id = ?
    WHERE u.school_id = ? 
        AND u.role_type = 'student' 
        AND u.is_active = true
        AND cs.status = 'active'
    GROUP BY u.id, u.name, cs.class_id, c.name
)
SELECT 
    student_id,
    student_name,
    class_id,
    class_name,
    present_days,
    total_days,
    attendance_percentage,
    CASE 
        WHEN attendance_percentage < 50 THEN 'critical'    -- < 50%
        WHEN attendance_percentage < 70 THEN 'high'        -- 50-70%
        WHEN attendance_percentage < 85 THEN 'medium'      -- 70-85%
        ELSE 'low'                                         -- > 85%
    END as risk_level
FROM student_attendance
ORDER BY attendance_percentage ASC
```

### **2. Classes with High Risk Query**
```sql
WITH student_risk_by_class AS (
    SELECT 
        c.id as class_id,
        c.name as class_name,
        c.grade_level,
        u.id as student_id,
        u.name as student_name,
        COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
        COUNT(a.id) as total_days,
        CASE 
            WHEN COUNT(a.id) = 0 THEN 0
            ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
        END as attendance_percentage,
        CASE 
            WHEN COUNT(a.id) = 0 OR (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < 50 THEN 'critical'
            WHEN (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < 70 THEN 'high'
            WHEN (COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)) < 85 THEN 'medium'
            ELSE 'low'
        END as risk_level
    FROM classes c
    INNER JOIN class_students cs ON c.id = cs.class_id
    INNER JOIN users u ON cs.student_id = u.id
    LEFT JOIN attendances a ON u.id = a.student_id 
        AND a.attendance_date >= ? -- 30 days ago
        AND a.school_id = ?
    WHERE c.school_id = ? 
        AND u.role_type = 'student' 
        AND u.is_active = true
        AND cs.status = 'active'
    GROUP BY c.id, c.name, c.grade_level, u.id, u.name
)
SELECT 
    class_id,
    class_name,
    grade_level,
    COUNT(*) as total_students,
    COUNT(CASE WHEN risk_level = 'critical' THEN 1 END) as critical_students,
    COUNT(CASE WHEN risk_level = 'high' THEN 1 END) as high_students,
    COUNT(CASE WHEN risk_level = 'medium' THEN 1 END) as medium_students,
    COUNT(CASE WHEN risk_level = 'low' THEN 1 END) as low_students,
    ROUND((COUNT(CASE WHEN risk_level IN ('critical', 'high') THEN 1 END) * 100.0 / COUNT(*)), 2) as high_risk_percentage
FROM student_risk_by_class
GROUP BY class_id, class_name, grade_level
ORDER BY high_risk_percentage DESC, critical_students DESC
LIMIT 10
```

### **3. Risk Trend 30 Days Query**
```sql
WITH daily_student_risk AS (
    SELECT 
        u.id as student_id,
        COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) as present_days,
        COUNT(a.id) as total_days,
        CASE 
            WHEN COUNT(a.id) = 0 THEN 0
            ELSE ROUND((COUNT(CASE WHEN a.status IN ('present', 'late') THEN 1 END) * 100.0 / COUNT(a.id)), 2)
        END as attendance_percentage
    FROM users u
    LEFT JOIN attendances a ON u.id = a.student_id 
        AND a.attendance_date BETWEEN ? AND ? -- 7-day rolling window
        AND a.school_id = ?
    WHERE u.school_id = ? 
        AND u.role_type = 'student' 
        AND u.is_active = true
    GROUP BY u.id
)
SELECT 
    COUNT(CASE WHEN attendance_percentage < 50 THEN 1 END) as critical_count,
    COUNT(CASE WHEN attendance_percentage >= 50 AND attendance_percentage < 70 THEN 1 END) as high_count,
    COUNT(CASE WHEN attendance_percentage >= 70 AND attendance_percentage < 85 THEN 1 END) as medium_count,
    COUNT(CASE WHEN attendance_percentage >= 85 THEN 1 END) as low_count,
    COUNT(*) as total_count
FROM daily_student_risk
```

---

## 📋 **DASHBOARD JSON OUTPUT**

### **API Endpoint**: `GET /api/v1/admin/risk-overview/`

```json
{
  "success": true,
  "data": {
    "total_students_by_risk": {
      "risk_counts": {
        "critical": 12,
        "high": 28,
        "medium": 45,
        "low": 315
      },
      "total_students": 400,
      "risk_percentages": {
        "critical": 3.0,
        "high": 7.0,
        "medium": 11.25,
        "low": 78.75
      }
    },
    "classes_with_high_risk": [
      {
        "class_id": 15,
        "class_name": "XII IPA 2",
        "grade_level": "XII",
        "total_students": 32,
        "critical_students": 4,
        "high_students": 8,
        "medium_students": 12,
        "low_students": 8,
        "high_risk_percentage": 37.5,
        "risk_distribution": {
          "critical": 12.5,
          "high": 25.0,
          "medium": 37.5,
          "low": 25.0
        }
      },
      {
        "class_id": 8,
        "class_name": "X IPS 1",
        "grade_level": "X",
        "total_students": 30,
        "critical_students": 2,
        "high_students": 6,
        "medium_students": 10,
        "low_students": 12,
        "high_risk_percentage": 26.7,
        "risk_distribution": {
          "critical": 6.7,
          "high": 20.0,
          "medium": 33.3,
          "low": 40.0
        }
      },
      {
        "class_id": 22,
        "class_name": "XI IPA 3",
        "grade_level": "XI",
        "total_students": 28,
        "critical_students": 1,
        "high_students": 5,
        "medium_students": 8,
        "low_students": 14,
        "high_risk_percentage": 21.4,
        "risk_distribution": {
          "critical": 3.6,
          "high": 17.9,
          "medium": 28.6,
          "low": 50.0
        }
      }
    ],
    "risk_trend_30_days": [
      {
        "date": "2026-01-01",
        "day_name": "Wednesday",
        "critical": 15,
        "high": 32,
        "medium": 48,
        "low": 305,
        "total": 400
      },
      {
        "date": "2026-01-02",
        "day_name": "Thursday",
        "critical": 14,
        "high": 30,
        "medium": 46,
        "low": 310,
        "total": 400
      },
      {
        "date": "2026-01-03",
        "day_name": "Friday",
        "critical": 13,
        "high": 29,
        "medium": 45,
        "low": 313,
        "total": 400
      },
      {
        "date": "2026-01-30",
        "day_name": "Thursday",
        "critical": 12,
        "high": 28,
        "medium": 45,
        "low": 315,
        "total": 400
      }
    ],
    "critical_students": [
      {
        "student_id": 1234,
        "student_name": "Ahmad Rizki Pratama",
        "email": "ahmad.rizki@student.school.id",
        "class_name": "XII IPA 2",
        "grade_level": "XII",
        "attendance_stats": {
          "present_days": 8,
          "total_days": 22,
          "alpha_days": 12,
          "sick_days": 1,
          "permit_days": 1,
          "attendance_percentage": 36.4
        },
        "last_attendance_date": "2026-01-15",
        "days_since_last_attendance": 16,
        "risk_level": "critical",
        "action_required": "Panggil orang tua segera - Risiko putus sekolah"
      },
      {
        "student_id": 1235,
        "student_name": "Siti Nurhaliza",
        "email": "siti.nurhaliza@student.school.id",
        "class_name": "X IPS 1",
        "grade_level": "X",
        "attendance_stats": {
          "present_days": 10,
          "total_days": 22,
          "alpha_days": 10,
          "sick_days": 2,
          "permit_days": 0,
          "attendance_percentage": 45.5
        },
        "last_attendance_date": "2026-01-20",
        "days_since_last_attendance": 11,
        "risk_level": "critical",
        "action_required": "Konseling intensif - Buat rencana perbaikan"
      }
    ],
    "summary_stats": {
      "total_active_students": 400,
      "total_classes": 15,
      "total_school_days": 22,
      "school_avg_attendance_rate": 87.3,
      "analysis_period": "30 days",
      "analysis_start_date": "2026-01-01",
      "analysis_end_date": "2026-01-31"
    },
    "generated_at": "2026-01-31T10:30:00.000Z"
  },
  "message": "Risk overview retrieved successfully"
}
```

---

## 📈 **DASHBOARD FEATURES IMPLEMENTED**

### **1. Risk Level Distribution**
- **Critical**: < 50% attendance (Red)
- **High**: 50-70% attendance (Orange) 
- **Medium**: 70-85% attendance (Yellow)
- **Low**: > 85% attendance (Green)

### **2. Interactive Elements**
- Click on risk level cards to see detailed student list
- Export functionality (Excel, PDF, CSV)
- Real-time data refresh
- Responsive design for mobile/tablet

### **3. Key Metrics Displayed**
- Total active students
- School average attendance rate
- Critical students count
- High-risk students count
- Classes ranked by risk percentage
- 30-day trend analysis

### **4. Action-Oriented Insights**
- Specific action recommendations for each critical student
- Class-level risk analysis for targeted interventions
- Trend data to identify improving/worsening patterns
- Export capabilities for reporting to stakeholders

### **5. Performance Optimizations**
- Cached results (1 hour TTL)
- Optimized SQL queries with proper indexing
- Pagination for large datasets
- Lazy loading for detailed views

---

## 🎯 **BUSINESS VALUE**

### **For School Administrators:**
- **Early Warning System**: Identify at-risk students before they drop out
- **Resource Allocation**: Focus intervention efforts on highest-risk classes
- **Performance Tracking**: Monitor improvement over time
- **Compliance Reporting**: Generate reports for education authorities

### **For Teachers:**
- **Targeted Support**: Know which students need immediate attention
- **Class Management**: Understand class-level attendance patterns
- **Parent Communication**: Data-driven conversations with parents
- **Intervention Planning**: Evidence-based support strategies

### **For Parents:**
- **Transparency**: Clear understanding of their child's attendance risk
- **Early Intervention**: Opportunity to address issues before they escalate
- **Progress Monitoring**: Track improvement over time
- **School Partnership**: Collaborative approach to student success

---

## 🔧 **TECHNICAL IMPLEMENTATION**

### **Backend Architecture:**
- **Service Layer**: `StudentRiskAnalysisService` for business logic
- **Controller Layer**: `RiskOverviewController` for API endpoints
- **Caching Strategy**: Redis caching with smart invalidation
- **Database Optimization**: Composite indexes for fast queries

### **Frontend Architecture:**
- **React Component**: Responsive dashboard with TypeScript
- **State Management**: Local state with API integration
- **UI Components**: Reusable card and table components
- **Export Integration**: Direct download links for reports

### **Security & Performance:**
- **Authorization**: Role-based access (school_admin, principal, super_admin)
- **Rate Limiting**: API throttling to prevent abuse
- **Data Validation**: Input sanitization and validation
- **Error Handling**: Comprehensive error management

---

*Dashboard JSON Documentation - Generated: 31 Januari 2026*