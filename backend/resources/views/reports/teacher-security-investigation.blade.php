<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Teacher Attendance Security Investigation Report</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'DejaVu Sans', sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #1f2937;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            border-bottom: 3px solid #1f2937;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }
        
        .header h1 {
            font-size: 16pt;
            color: #111827;
            margin-bottom: 5px;
        }
        
        .header .subtitle {
            font-size: 10pt;
            color: #6b7280;
        }
        
        .meta-info {
            display: table;
            width: 100%;
            margin-bottom: 20px;
            font-size: 9pt;
        }
        
        .meta-info .left, .meta-info .right {
            display: table-cell;
            width: 50%;
        }
        
        .meta-info .right {
            text-align: right;
        }
        
        .risk-badge {
            display: inline-block;
            padding: 8px 20px;
            border-radius: 4px;
            color: white;
            font-weight: bold;
            font-size: 12pt;
            text-align: center;
            margin: 15px 0;
        }
        
        .risk-critical { background-color: #dc2626; }
        .risk-high { background-color: #ea580c; }
        .risk-medium { background-color: #ca8a04; }
        .risk-low { background-color: #16a34a; }
        
        .section {
            margin-bottom: 20px;
            page-break-inside: avoid;
        }
        
        .section-title {
            background-color: #1f2937;
            color: white;
            padding: 8px 12px;
            font-size: 11pt;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .section-content {
            padding: 10px;
            background-color: #f9fafb;
            border: 1px solid #e5e7eb;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }
        
        table th, table td {
            padding: 6px 8px;
            text-align: left;
            border: 1px solid #d1d5db;
            font-size: 9pt;
        }
        
        table th {
            background-color: #e5e7eb;
            font-weight: bold;
        }
        
        table tr:nth-child(even) {
            background-color: #f3f4f6;
        }
        
        .data-row {
            margin-bottom: 5px;
        }
        
        .data-label {
            font-weight: bold;
            color: #374151;
            display: inline-block;
            width: 180px;
        }
        
        .data-value {
            color: #1f2937;
        }
        
        .alert-item {
            padding: 8px;
            margin-bottom: 5px;
            border-left: 3px solid #6b7280;
            background-color: #f9fafb;
        }
        
        .alert-critical { border-left-color: #dc2626; }
        .alert-high { border-left-color: #ea580c; }
        .alert-medium { border-left-color: #ca8a04; }
        .alert-low { border-left-color: #16a34a; }
        
        .stat-grid {
            display: table;
            width: 100%;
        }
        
        .stat-box {
            display: table-cell;
            width: 25%;
            padding: 10px;
            text-align: center;
            border: 1px solid #e5e7eb;
        }
        
        .stat-value {
            font-size: 18pt;
            font-weight: bold;
            color: #1f2937;
        }
        
        .stat-label {
            font-size: 8pt;
            color: #6b7280;
        }
        
        .warning-box {
            background-color: #fef2f2;
            border: 1px solid #fecaca;
            padding: 10px;
            margin: 10px 0;
        }
        
        .success-box {
            background-color: #f0fdf4;
            border: 1px solid #bbf7d0;
            padding: 10px;
            margin: 10px 0;
        }
        
        .conclusion-box {
            border: 2px solid {{ $risk_color }};
            padding: 15px;
            margin-top: 20px;
        }
        
        .conclusion-title {
            font-size: 12pt;
            font-weight: bold;
            color: {{ $risk_color }};
            margin-bottom: 10px;
        }
        
        .recommendation-list {
            margin-top: 10px;
            padding-left: 20px;
        }
        
        .recommendation-list li {
            margin-bottom: 5px;
        }
        
        .footer {
            position: fixed;
            bottom: 20px;
            left: 20px;
            right: 20px;
            text-align: center;
            font-size: 8pt;
            color: #9ca3af;
            border-top: 1px solid #e5e7eb;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>TEACHER ATTENDANCE SECURITY INVESTIGATION REPORT</h1>
        <div class="subtitle">{{ $school['name'] }}</div>
    </div>
    
    <!-- Meta Information -->
    <div class="meta-info">
        <div class="left">
            <strong>Report ID:</strong> {{ $report_id }}<br>
            <strong>Generated:</strong> {{ $generated_at }}<br>
            <strong>Period:</strong> {{ $period_start }} to {{ $period_end }}
        </div>
        <div class="right">
            <strong>Teacher:</strong> {{ $teacher['name'] }}<br>
            <strong>School:</strong> {{ $school['name'] }}
        </div>
    </div>
    
    <!-- Risk Level Badge -->
    <div style="text-align: center;">
        <div class="risk-badge risk-{{ $risk_level }}">
            RISK LEVEL: {{ strtoupper($risk_level) }}
        </div>
    </div>
    
    <!-- Section 1: Teacher Identity -->
    <div class="section">
        <div class="section-title">SECTION 1: TEACHER IDENTITY</div>
        <div class="section-content">
            <div class="data-row">
                <span class="data-label">Full Name:</span>
                <span class="data-value">{{ $teacher['name'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Email:</span>
                <span class="data-value">{{ $teacher['email'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">School:</span>
                <span class="data-value">{{ $teacher['school_name'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Role:</span>
                <span class="data-value">{{ ucfirst($teacher['role']) }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Account Status:</span>
                <span class="data-value">{{ $teacher['is_active'] ? 'Active' : 'Inactive' }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Bound Device ID:</span>
                <span class="data-value">{{ $teacher['bound_device_id'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Device Bound At:</span>
                <span class="data-value">{{ $teacher['device_bound_at'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Last Login Device:</span>
                <span class="data-value">{{ $teacher['last_login_device'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Last Activity:</span>
                <span class="data-value">{{ $teacher['last_login_at'] }}</span>
            </div>
        </div>
    </div>
    
    <!-- Section 2: Summary of Suspicious Activity -->
    <div class="section">
        <div class="section-title">SECTION 2: SUMMARY OF SUSPICIOUS ACTIVITY</div>
        <div class="section-content">
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="stat-value">{{ $attendance['total_student_scans'] }}</div>
                    <div class="stat-label">Total Scans</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: {{ $attendance['failed_scans'] > 10 ? '#dc2626' : '#16a34a' }}">
                        {{ $attendance['failed_scans'] }}
                    </div>
                    <div class="stat-label">Failed Scans</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: {{ $attendance['outside_radius_attempts'] > 0 ? '#ea580c' : '#16a34a' }}">
                        {{ $attendance['outside_radius_attempts'] }}
                    </div>
                    <div class="stat-label">Outside Radius</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: {{ $attendance['schedule_violations'] > 0 ? '#ca8a04' : '#16a34a' }}">
                        {{ $attendance['schedule_violations'] }}
                    </div>
                    <div class="stat-label">Schedule Violations</div>
                </div>
            </div>
            
            <table style="margin-top: 15px;">
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                    <th>Status</th>
                </tr>
                <tr>
                    <td>Success Rate</td>
                    <td>{{ $attendance['success_rate'] }}%</td>
                    <td>{{ $attendance['success_rate'] >= 90 ? '✓ Normal' : '⚠ Below Normal' }}</td>
                </tr>
                <tr>
                    <td>Teacher Present Days</td>
                    <td>{{ $attendance['teacher_present_days'] }}</td>
                    <td>-</td>
                </tr>
                <tr>
                    <td>Teacher Late Days</td>
                    <td>{{ $attendance['teacher_late_days'] }}</td>
                    <td>{{ $attendance['teacher_late_days'] > 3 ? '⚠ Frequent' : '✓ Normal' }}</td>
                </tr>
                <tr>
                    <td>Teacher Absent Days</td>
                    <td>{{ $attendance['teacher_absent_days'] }}</td>
                    <td>{{ $attendance['teacher_absent_days'] > 2 ? '⚠ Review' : '✓ Normal' }}</td>
                </tr>
            </table>
        </div>
    </div>
    
    <!-- Section 3: Behavior Risk Analysis -->
    <div class="section">
        <div class="section-title">SECTION 3: BEHAVIOR RISK ANALYSIS</div>
        <div class="section-content">
            <div class="data-row">
                <span class="data-label">Current Risk Level:</span>
                <span class="data-value" style="color: {{ $risk_color }}; font-weight: bold;">
                    {{ strtoupper($behavior['current_risk_level']) }}
                </span>
            </div>
            <div class="data-row">
                <span class="data-label">Current Risk Score:</span>
                <span class="data-value">{{ $behavior['current_risk_score'] }} points</span>
            </div>
            
            @if(!empty($behavior['triggered_flags']))
            <div class="warning-box">
                <strong>⚠ Triggered Security Flags:</strong>
                <ul style="margin-top: 5px; padding-left: 20px;">
                    @foreach($behavior['triggered_flags'] as $flag)
                        <li>{{ str_replace('_', ' ', ucfirst($flag)) }}</li>
                    @endforeach
                </ul>
            </div>
            @endif
            
            <h4 style="margin-top: 15px; margin-bottom: 10px;">Period Statistics</h4>
            <table>
                <tr>
                    <th>Metric</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Average Daily Scans</td>
                    <td>{{ $behavior['period_stats']['avg_daily_scans'] }}</td>
                </tr>
                <tr>
                    <td>Maximum Daily Scans</td>
                    <td>{{ $behavior['period_stats']['max_daily_scans'] }}</td>
                </tr>
                <tr>
                    <td>Total Failed Scans</td>
                    <td>{{ $behavior['period_stats']['total_failed_scans'] }}</td>
                </tr>
                <tr>
                    <td>Average Failed Ratio</td>
                    <td>{{ $behavior['period_stats']['avg_failed_ratio'] }}%</td>
                </tr>
                <tr>
                    <td>Outside Radius Attempts</td>
                    <td>{{ $behavior['period_stats']['total_outside_radius'] }}</td>
                </tr>
                <tr>
                    <td>Device Mismatch Attempts</td>
                    <td>{{ $behavior['period_stats']['total_device_mismatch'] }}</td>
                </tr>
                <tr>
                    <td>Days with Rapid Scanning</td>
                    <td>{{ $behavior['period_stats']['days_with_rapid_scanning'] }}</td>
                </tr>
            </table>
            
            @if($behavior['baseline'])
            <h4 style="margin-top: 15px; margin-bottom: 10px;">Baseline Comparison</h4>
            <table>
                <tr>
                    <th>Baseline Metric</th>
                    <th>Value</th>
                </tr>
                <tr>
                    <td>Avg Scans Per Day (Baseline)</td>
                    <td>{{ $behavior['baseline']['avg_scans_per_day'] }}</td>
                </tr>
                <tr>
                    <td>Avg Failed Ratio (Baseline)</td>
                    <td>{{ $behavior['baseline']['avg_failed_ratio'] }}%</td>
                </tr>
                <tr>
                    <td>Days in Baseline</td>
                    <td>{{ $behavior['baseline']['days_in_baseline'] }}</td>
                </tr>
            </table>
            @endif
        </div>
    </div>
    
    <div class="page-break"></div>
    
    <!-- Section 4: Alert Log Timeline -->
    <div class="section">
        <div class="section-title">SECTION 4: ALERT LOG TIMELINE</div>
        <div class="section-content">
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="stat-value">{{ $alerts['total_alerts'] }}</div>
                    <div class="stat-label">Total Alerts</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: #dc2626;">{{ $alerts['critical_alerts'] }}</div>
                    <div class="stat-label">Critical</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: #ea580c;">{{ $alerts['high_alerts'] }}</div>
                    <div class="stat-label">High</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: #ca8a04;">{{ $alerts['unresolved_alerts'] }}</div>
                    <div class="stat-label">Unresolved</div>
                </div>
            </div>
            
            @if(count($alerts['timeline']) > 0)
            <h4 style="margin-top: 15px; margin-bottom: 10px;">Recent Alerts</h4>
            @foreach(array_slice($alerts['timeline'], 0, 10) as $alert)
            <div class="alert-item alert-{{ $alert['severity'] }}">
                <strong>[{{ strtoupper($alert['severity']) }}]</strong> {{ $alert['type'] }}<br>
                <small>{{ $alert['timestamp'] }} | {{ $alert['is_resolved'] ? 'Resolved' : 'Unresolved' }}</small><br>
                {{ $alert['description'] }}
                @if($alert['device_id'])
                <br><small>Device: {{ $alert['device_id'] }}</small>
                @endif
            </div>
            @endforeach
            
            @if(count($alerts['timeline']) > 10)
            <p style="margin-top: 10px; color: #6b7280; font-style: italic;">
                ... and {{ count($alerts['timeline']) - 10 }} more alerts
            </p>
            @endif
            @else
            <div class="success-box">
                ✓ No security alerts recorded during this period.
            </div>
            @endif
        </div>
    </div>
    
    <!-- Section 5: Location Scan Analysis -->
    <div class="section">
        <div class="section-title">SECTION 5: LOCATION SCAN ANALYSIS</div>
        <div class="section-content">
            <div class="stat-grid">
                <div class="stat-box">
                    <div class="stat-value">{{ $location['total_scan_clusters'] }}</div>
                    <div class="stat-label">Total Clusters</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: #16a34a;">{{ $location['clusters_inside_school'] }}</div>
                    <div class="stat-label">Inside School</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value" style="color: {{ $location['clusters_outside_school'] > 0 ? '#dc2626' : '#16a34a' }};">
                        {{ $location['clusters_outside_school'] }}
                    </div>
                    <div class="stat-label">Outside School</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value">{{ $location['school_radius_meters'] }}m</div>
                    <div class="stat-label">School Radius</div>
                </div>
            </div>
            
            <table style="margin-top: 15px;">
                <tr>
                    <th>Location Zone</th>
                    <th>Scan Clusters</th>
                    <th>Total Scans</th>
                </tr>
                <tr>
                    <td>Inside School Zone</td>
                    <td>{{ $location['clusters_inside_school'] }}</td>
                    <td>{{ $location['total_scans_inside'] }}</td>
                </tr>
                <tr style="background-color: {{ $location['clusters_outside_school'] > 0 ? '#fef2f2' : '#f0fdf4' }};">
                    <td>Outside School Zone</td>
                    <td>{{ $location['clusters_outside_school'] }}</td>
                    <td>{{ $location['total_scans_outside'] }}</td>
                </tr>
            </table>
            
            @if(count($location['outside_zone_details']) > 0)
            <div class="warning-box" style="margin-top: 15px;">
                <strong>⚠ Outside Zone Scan Locations:</strong>
                <table style="margin-top: 10px;">
                    <tr>
                        <th>Latitude</th>
                        <th>Longitude</th>
                        <th>Distance</th>
                        <th>Scan Count</th>
                    </tr>
                    @foreach($location['outside_zone_details'] as $zone)
                    <tr>
                        <td>{{ number_format($zone['lat'], 6) }}</td>
                        <td>{{ number_format($zone['lng'], 6) }}</td>
                        <td>{{ $zone['distance_from_school'] }}m</td>
                        <td>{{ $zone['scan_count'] }}</td>
                    </tr>
                    @endforeach
                </table>
            </div>
            @else
            <div class="success-box" style="margin-top: 15px;">
                ✓ All scans were performed within the authorized school zone.
            </div>
            @endif
        </div>
    </div>
    
    <!-- Section 6: Device Integrity Check -->
    <div class="section">
        <div class="section-title">SECTION 6: DEVICE INTEGRITY CHECK</div>
        <div class="section-content">
            <div class="data-row">
                <span class="data-label">Bound Device:</span>
                <span class="data-value">{{ $device['bound_device'] ?? 'Not Bound' }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Total Devices Used:</span>
                <span class="data-value">{{ $device['total_devices_used'] }}</span>
            </div>
            <div class="data-row">
                <span class="data-label">Device Integrity:</span>
                <span class="data-value" style="color: {{ $device['device_integrity'] === 'PASSED' ? '#16a34a' : '#dc2626' }}; font-weight: bold;">
                    {{ $device['device_integrity'] }}
                </span>
            </div>
            
            @if(count($device['devices']) > 0)
            <h4 style="margin-top: 15px; margin-bottom: 10px;">Device Usage History</h4>
            <table>
                <tr>
                    <th>Device ID</th>
                    <th>Status</th>
                    <th>Usage Count</th>
                    <th>First Used</th>
                    <th>Last Used</th>
                </tr>
                @foreach($device['devices'] as $dev)
                <tr style="background-color: {{ $dev['is_bound'] ? '#f0fdf4' : '#fef2f2' }};">
                    <td>{{ Str::limit($dev['device_id'], 20) }}</td>
                    <td>{{ $dev['is_bound'] ? '✓ Bound' : '⚠ Unbound' }}</td>
                    <td>{{ $dev['usage_count'] }}</td>
                    <td>{{ $dev['first_used'] }}</td>
                    <td>{{ $dev['last_used'] }}</td>
                </tr>
                @endforeach
            </table>
            @endif
            
            @if($device['mismatch_count'] > 0)
            <div class="warning-box" style="margin-top: 15px;">
                <strong>⚠ Device Mismatch Detected:</strong><br>
                {{ $device['mismatch_count'] }} unauthorized device(s) were used for attendance scanning.
                This may indicate the teacher is using multiple devices or sharing credentials.
            </div>
            @else
            <div class="success-box" style="margin-top: 15px;">
                ✓ No device integrity issues detected. All scans match the bound device.
            </div>
            @endif
        </div>
    </div>
    
    <!-- Section 7: System Conclusion -->
    <div class="section">
        <div class="section-title">SECTION 7: SYSTEM CONCLUSION</div>
        <div class="conclusion-box">
            <div class="conclusion-title">
                RISK ASSESSMENT: {{ $conclusion['risk_level'] }}
            </div>
            <p style="margin-bottom: 15px;">{{ $conclusion['summary'] }}</p>
            
            @if(count($conclusion['recommendations']) > 0)
            <strong>Recommendations:</strong>
            <ul class="recommendation-list">
                @foreach($conclusion['recommendations'] as $recommendation)
                <li>{{ $recommendation }}</li>
                @endforeach
            </ul>
            @endif
        </div>
    </div>
    
    <!-- Disclaimer -->
    <div style="margin-top: 30px; padding: 10px; background-color: #f3f4f6; font-size: 8pt; color: #6b7280;">
        <strong>DISCLAIMER:</strong> This report is auto-generated by the AbsensiQRPro security monitoring system.
        The findings are based on system data analysis and should be reviewed by authorized personnel before
        taking any administrative action. This report is confidential and intended only for authorized school
        administrators.
    </div>
    
    <!-- Footer -->
    <div class="footer">
        Generated by AbsensiQRPro Security System | {{ $generated_at }} | Report ID: {{ $report_id }}
    </div>
</body>
</html>
