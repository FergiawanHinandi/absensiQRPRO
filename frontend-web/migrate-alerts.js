#!/usr/bin/env node

/**
 * Alert to Toast Migration Helper
 * 
 * This script helps identify and migrate alert() calls to toast notifications
 * 
 * Usage:
 *   node migrate-alerts.js scan          - Scan for all alert() usage
 *   node migrate-alerts.js scan --file filepath - Scan specific file
 *   node migrate-alerts.js stats         - Show migration statistics
 */

const fs = require('fs');
const path = require('path');

const TOAST_IMPORT = `import showToast from '../../../utils/toast';`;

// Alert patterns and their toast equivalents
const ALERT_PATTERNS = [
    {
        pattern: /alert\(['"](.+berhasil.+)['"]\)/gi,
        replacement: (match, p1) => `showToast.success('${p1}')`,
        type: 'success'
    },
    {
        pattern: /alert\(['"](.+gagal.+)['"]\)/gi,
        replacement: (match, p1) => `showToast.error('${p1}')`,
        type: 'error'
    },
    {
        pattern: /alert\(['"](.+tidak.+)['"]\)/gi,
        replacement: (match, p1) => `showToast.warning('${p1}')`,
        type: 'warning'
    },
    {
        pattern: /alert\(['"](.+minimal.+)['"]\)/gi,
        replacement: (match, p1) => `showToast.warning('${p1}')`,
        type: 'warning'
    },
    {
        pattern: /alert\((['"].*?['"])\)/gi,
        replacement: (match, p1) => `showToast.info(${p1})`,
        type: 'info'
    },
    // Error from response
    {
        pattern: /alert\(error\.response\?\.data\?\.message \|\| ['"](.+)['"]\)/gi,
        replacement: (match, p1) => `showToast.error(error.response?.data?.message || '${p1}')`,
        type: 'error'
    },
    // Variable alerts
    {
        pattern: /alert\((\w+)\)/gi,
        replacement: (match, p1) => `showToast.info(${p1})`,
        type: 'info'
    }
];

function findAlerts(directory, results = []) {
    const files = fs.readdirSync(directory);

    files.forEach(file => {
        const filepath = path.join(directory, file);
        const stat = fs.statSync(filepath);

        if (stat.isDirectory()) {
            if (!filepath.includes('node_modules') && !filepath.includes('.git')) {
                findAlerts(filepath, results);
            }
        } else if (file.endsWith('.tsx') || file.endsWith('.ts')) {
            const content = fs.readFileSync(filepath, 'utf8');
            const lines = content.split('\n');

            lines.forEach((line, index) => {
                if (line.includes('alert(') && !line.trim().startsWith('//')) {
                    results.push({
                        file: filepath,
                        line: index + 1,
                        content: line.trim()
                    });
                }
            });
        }
    });

    return results;
}

function showScanResults(results) {
    console.log('\n🔍 Alert Usage Scan Results\n');
    console.log(`Found ${results.length} alert() calls\n`);

    // Group by file
    const byFile = {};
    results.forEach(r => {
        if (!byFile[r.file]) byFile[r.file] = [];
        byFile[r.file].push(r);
    });

    Object.entries(byFile).forEach(([file, alerts]) => {
        const relPath = path.relative(process.cwd(), file);
        console.log(`\n📄 ${relPath} (${alerts.length} alerts)`);
        alerts.forEach(alert => {
            console.log(`  Line ${alert.line}: ${alert.content}`);
        });
    });

    console.log('\n---');
    console.log(`Total files with alerts: ${Object.keys(byFile).length}`);
    console.log(`Total alert() calls: ${results.length}`);
    console.log('\n💡 Tip: Run this script on each file to help with migration\n');
}

function showStats() {
    const alerts = findAlerts(path.join(process.cwd(), 'src'));

    console.log('\n📊 Migration Statistics\n');

    // Group by directory
    const byDir = {};
    alerts.forEach(a => {
        const dir = path.dirname(a.file).split(path.sep).slice(-2).join('/');
        if (!byDir[dir]) byDir[dir] = 0;
        byDir[dir]++;
    });

    console.log('Alerts by directory:');
    Object.entries(byDir)
        .sort((a, b) => b[1] - a[1])
        .forEach(([dir, count]) => {
            console.log(`  ${dir}: ${count} alerts`);
        });

    console.log(`\nTotal: ${alerts.length} alerts to migrate`);
}

function suggestReplacement(alertCall) {
    for (const pattern of ALERT_PATTERNS) {
        if (pattern.pattern.test(alertCall)) {
            return {
                type: pattern.type,
                replacement: alertCall.replace(pattern.pattern, pattern.replacement)
            };
        }
    }
    return null;
}

function analyzeFile(filepath) {
    const content = fs.readFileSync(filepath, 'utf8');
    const lines = content.split('\n');
    const alerts = [];

    lines.forEach((line, index) => {
        if (line.includes('alert(') && !line.trim().startsWith('//')) {
            const match = line.match(/alert\([^)]+\)/);
            if (match) {
                const suggestion = suggestReplacement(match[0]);
                alerts.push({
                    line: index + 1,
                    original: line.trim(),
                    alert: match[0],
                    suggestion
                });
            }
        }
    });

    if (alerts.length > 0) {
        console.log(`\n📄 ${path.relative(process.cwd(), filepath)}\n`);
        console.log(`Found ${alerts.length} alert() calls:\n`);

        alerts.forEach(a => {
            console.log(`Line ${a.line}:`);
            console.log(`  Current:  ${a.alert}`);
            if (a.suggestion) {
                console.log(`  Suggested: ${a.suggestion.replacement} (${a.suggestion.type})`);
            }
            console.log('');
        });

        // Check if toast import exists
        if (!content.includes('showToast')) {
            console.log('⚠️  Missing toast import. Add this to the top of the file:');
            console.log(`  ${TOAST_IMPORT}\n`);
        } else {
            console.log('✅ Toast import already exists\n');
        }
    } else {
        console.log(`\n✅ No alerts found in ${path.basename(filepath)}\n`);
    }
}

// Main execution
const args = process.argv.slice(2);
const command = args[0];

if (!command || command === 'scan') {
    const filePath = args.findIndex(a => a === '--file');
    if (filePath !== -1 && args[filePath + 1]) {
        analyzeFile(args[filePath + 1]);
    } else {
        const results = findAlerts(path.join(process.cwd(), 'src'));
        showScanResults(results);
    }
} else if (command === 'stats') {
    showStats();
} else {
    console.log(`
Alert to Toast Migration Helper

Usage:
  node migrate-alerts.js scan              - Scan all files for alert() usage
  node migrate-alerts.js scan --file <path> - Analyze specific file
  node migrate-alerts.js stats             - Show migration statistics

Examples:
  node migrate-alerts.js scan
  node migrate-alerts.js scan --file src/pages/Admin/AdminTeachers.tsx
  node migrate-alerts.js stats
    `);
}
