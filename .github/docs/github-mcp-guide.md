# 📘 GitHub MCP Server Integration Guide for VS Code

Panduan lengkap untuk mengintegrasikan dan menggunakan GitHub MCP Server dengan VS Code Copilot.

## 📋 Daftar Isi

- [Pendahuluan](#pendahuluan)
- [Setup & Instalasi](#setup--instalasi)
- [Daftar Tools yang Tersedia](#daftar-tools-yang-tersedia)
- [Common Workflows](#common-workflows)
- [Best Practices](#best-practices)
- [Troubleshooting](#troubleshooting)

---

## 🎯 Pendahuluan

GitHub MCP (Model Context Protocol) Server adalah extension yang memungkinkan VS Code Copilot untuk berinteraksi langsung dengan GitHub API. Dengan integrasi ini, Copilot dapat:

- ✅ Membuat dan mengelola issues
- ✅ Membuat dan me-review pull requests
- ✅ Mencari code di seluruh repository
- ✅ Mengelola commits dan branches
- ✅ Menganalisis workflow Actions
- ✅ Dan banyak lagi!

### 💡 Keuntungan Menggunakan GitHub MCP Server

1. **Otomasi Penuh**: Copilot dapat melakukan tugas GitHub langsung dari IDE
2. **Efisiensi Tinggi**: Tidak perlu context switching ke browser
3. **Intelligent Actions**: Copilot memahami context repository dan bisa memberikan saran yang lebih baik
4. **Dokumentasi Otomatis**: Generate dokumentasi dan changelog secara otomatis

---

## 🚀 Setup & Instalasi

### Prasyarat

- VS Code versi terbaru
- GitHub Copilot extension terinstall
- GitHub account dengan akses ke repository yang diinginkan

### Langkah Instalasi

#### 1. Install GitHub MCP Server Extension

> **Note:** Nama extension dan metode instalasi dapat bervariasi. Pastikan untuk memeriksa VS Code Marketplace untuk extension yang tepat untuk GitHub MCP Server.

```bash
# Install via VS Code Marketplace (verify extension name in marketplace)
code --install-extension github.mcp-server
```

Atau install melalui:
1. Buka VS Code
2. Tekan `Ctrl+Shift+X` (Windows/Linux) atau `Cmd+Shift+X` (Mac)
3. Cari "GitHub MCP Server" atau "Model Context Protocol"
4. Klik "Install"

#### 2. Konfigurasi GitHub Token

Buat Personal Access Token (PAT) di GitHub. GitHub menyediakan dua jenis token:

**Fine-grained Personal Access Token (Recommended untuk security):**
1. Pergi ke GitHub Settings → Developer settings → Personal access tokens → Fine-grained tokens
2. Generate new token dengan specific repository access

**Classic Personal Access Token:**
1. Pergi ke GitHub Settings → Developer settings → Personal access tokens → Tokens (classic)
2. Klik "Generate new token (classic)"
3. Berikan nama token yang deskriptif (misal: "VS Code MCP Server")
4. Pilih scopes yang dibutuhkan:
   - ✅ `repo` (Full control of private repositories)
   - ✅ `workflow` (Update GitHub Action workflows)
   - ✅ `read:org` (Read org and team membership)
   - ✅ `user` (Update user data)
5. Generate token dan **simpan token dengan aman**

#### 3. Konfigurasi di VS Code

> **Note:** Setting names dapat bervariasi tergantung versi extension. Periksa dokumentasi extension untuk setting yang tepat.

**Metode 1: Via Settings UI**
1. Buka Settings (`Ctrl+,`)
2. Cari "GitHub MCP"
3. Paste token di field GitHub MCP token

**Metode 2: Via settings.json (Example)**
```json
{
  "github.mcp.token": "ghp_your_token_here",
  "github.mcp.enabled": true
}
```

#### 4. Verifikasi Instalasi

Buka Copilot Chat dan ketik:
```
@workspace Test GitHub MCP connection - get my GitHub user info
```

Jika berhasil, Copilot akan menampilkan informasi user GitHub Anda.

---

## 🛠️ Daftar Tools yang Tersedia

### 1. Repository Management

#### `search_repositories`
Mencari repositories di GitHub.

**Contoh:**
```
Cari semua repository Python yang berhubungan dengan machine learning
```

**Parameters:**
- `query`: Search query (required)
- `sort`: Sort by (stars, forks, updated)
- `order`: asc/desc
- `perPage`: Results per page (max 100)

#### `get_file_contents`
Membaca isi file dari repository.

**Contoh:**
```
Baca file README.md dari repository main branch
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `path`: File path (default: "/")
- `ref`: Git reference (branch/tag)

---

### 2. Issue Management

#### `list_issues`
Menampilkan daftar issues di repository.

**Contoh:**
```
Tampilkan semua open issues dengan label "bug"
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `state`: open/closed
- `labels`: Filter by labels
- `perPage`: Results per page

#### `issue_read`
Membaca detail issue tertentu.

**Contoh:**
```
Tampilkan detail issue #123 termasuk komentarnya
```

**Methods:**
- `get`: Get issue details
- `get_comments`: Get issue comments
- `get_labels`: Get issue labels

#### `search_issues`
Mencari issues dengan query khusus.

**Contoh:**
```
Cari semua issues yang dibuat dalam 7 hari terakhir
```

---

### 3. Pull Request Management

#### `list_pull_requests`
Menampilkan daftar PRs.

**Contoh:**
```
Tampilkan semua open PRs yang ready for review
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `state`: open/closed/all
- `sort`: created/updated/popularity
- `direction`: asc/desc

#### `pull_request_read`
Membaca detail PR tertentu.

**Contoh:**
```
Tampilkan diff dari PR #45
```

**Methods:**
- `get`: Get PR details
- `get_diff`: Get PR diff
- `get_status`: Get commit status
- `get_files`: Get changed files
- `get_review_comments`: Get review threads
- `get_reviews`: Get reviews
- `get_comments`: Get PR comments

#### `search_pull_requests`
Mencari PRs dengan query khusus.

**Contoh:**
```
Cari semua PRs yang merged dalam sebulan terakhir
```

---

### 4. Commit & Branch Management

#### `list_commits`
Menampilkan daftar commits.

**Contoh:**
```
Tampilkan 10 commits terakhir di branch main
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `sha`: Branch/tag name
- `author`: Filter by author
- `perPage`: Results per page

#### `get_commit`
Melihat detail commit spesifik.

**Contoh:**
```
Tampilkan detail commit abc123 dengan diff-nya
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `sha`: Commit SHA (required)
- `include_diff`: Include diff (default: true)

#### `list_branches`
Menampilkan daftar branches.

**Contoh:**
```
Tampilkan semua branches di repository
```

---

### 5. Code Search

#### `search_code`
Mencari code di seluruh GitHub.

**Contoh:**
```
Cari penggunaan function "calculateTotal" dalam repository ini
```

**Query Syntax:**
```
content:authenticate language:Java org:github
repo:owner/repo NOT is:archived
language:Python OR language:go
```

**Parameters:**
- `query`: Search query (required)
- `sort`: indexed (only)
- `order`: asc/desc
- `perPage`: Results per page

---

### 6. Release Management

#### `list_releases`
Menampilkan daftar releases.

**Contoh:**
```
Tampilkan semua releases yang telah dipublish
```

#### `get_latest_release`
Mendapatkan release terbaru.

**Contoh:**
```
Tampilkan informasi release terbaru
```

#### `get_release_by_tag`
Mendapatkan release berdasarkan tag.

**Contoh:**
```
Tampilkan release dengan tag v1.0.0
```

---

### 7. GitHub Actions

#### `list_workflows`
Menampilkan daftar workflows.

**Contoh:**
```
Tampilkan semua GitHub Actions workflows
```

#### `list_workflow_runs`
Menampilkan daftar workflow runs.

**Contoh:**
```
Tampilkan 5 workflow runs terakhir untuk CI workflow
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `resource_id`: Workflow ID or workflow filename (e.g., "ci.yaml")
- `workflow_runs_filter`: Filter options
  - `status`: queued, in_progress, completed
  - `event`: push, pull_request, etc.
  - `branch`: Filter by branch

#### `get_workflow_run`
Melihat detail workflow run spesifik.

**Contoh:**
```
Tampilkan detail workflow run #12345
```

#### `get_job_logs`
Mendapatkan logs dari workflow job.

**Contoh:**
```
Tampilkan logs untuk job yang gagal
```

**Parameters:**
- `owner`: Repository owner (required)
- `repo`: Repository name (required)
- `job_id`: Job ID
- `run_id`: Run ID (for failed jobs)
- `failed_only`: Get all failed jobs
- `tail_lines`: Number of lines (default: 500)

---

### 8. Security & Scanning

#### `list_code_scanning_alerts`
Menampilkan code scanning alerts.

**Contoh:**
```
Tampilkan semua open security alerts
```

**Parameters:**
- `state`: open/closed/dismissed/fixed
- `severity`: critical/high/medium/low

#### `get_code_scanning_alert`
Melihat detail alert spesifik.

#### `list_secret_scanning_alerts`
Menampilkan secret scanning alerts.

**Contoh:**
```
Tampilkan semua open secret alerts
```

#### `get_secret_scanning_alert`
Melihat detail secret alert.

---

### 9. Labels & Tags

#### `get_label`
Mendapatkan informasi label.

**Contoh:**
```
Tampilkan detail label "bug"
```

#### `list_tags`
Menampilkan daftar git tags.

**Contoh:**
```
Tampilkan semua release tags
```

#### `get_tag`
Mendapatkan detail tag spesifik.

---

### 10. Search Users

#### `search_users`
Mencari GitHub users.

**Contoh:**
```
Cari users dengan expertise di machine learning
```

**Query Examples:**
```
john smith
location:seattle
followers:>100
```

---

## 🔄 Common Workflows

### Workflow 1: Membuat Issue Baru

```
Copilot, buatkan issue baru dengan:
- Title: "Add login authentication"
- Body: "Implementasi fitur login dengan JWT authentication"
- Labels: ["enhancement", "high-priority"]
- Assignee: FergiawanHinandi
```

**Tools yang digunakan:**
1. `issue_write` - Create issue
2. `add_issue_comment` - Add initial comment (optional)

---

### Workflow 2: Review Pull Request

```
Copilot, review PR #45:
1. Tampilkan summary changes
2. Analisa code diff
3. Cari potential bugs
4. Suggest improvements
```

**Tools yang digunakan:**
1. `pull_request_read` (method: get) - Get PR details
2. `pull_request_read` (method: get_diff) - Get code changes
3. `pull_request_read` (method: get_files) - Get changed files
4. `search_code` - Find similar patterns

---

### Workflow 3: Debugging CI/CD Failures

```
Copilot, debug workflow yang gagal:
1. Tampilkan failed workflow runs
2. Ambil logs dari job yang error
3. Identifikasi root cause
4. Suggest fix
```

**Tools yang digunakan:**
1. `list_workflow_runs` (filter: status=completed)
2. `get_job_logs` (failed_only: true)
3. `get_commit` - Check recent changes
4. `search_code` - Find related code

---

### Workflow 4: Code Search & Refactoring

```
Copilot, help me refactor:
1. Cari semua penggunaan deprecated function "oldApi()"
2. Tampilkan file mana saja yang menggunakan
3. Suggest replacement dengan "newApi()"
4. Estimate impact
```

**Tools yang digunakan:**
1. `search_code` (query: "oldApi repo:owner/repo")
2. `get_file_contents` - Read affected files
3. `search_code` - Verify changes across repository

---

### Workflow 5: Release Management

```
Copilot, prepare for release v2.0:
1. Tampilkan commits sejak release terakhir
2. Generate changelog
3. List all closed issues and merged PRs
4. Suggest release notes
```

**Tools yang digunakan:**
1. `get_latest_release` - Get last release
2. `list_commits` - Get commits since last release
3. `search_issues` (query: "is:closed closed:>YYYY-MM-DD") - Replace YYYY-MM-DD dengan tanggal release terakhir
4. `search_pull_requests` (query: "is:merged merged:>YYYY-MM-DD") - Replace YYYY-MM-DD dengan tanggal release terakhir

---

### Workflow 6: Security Audit

```
Copilot, lakukan security audit:
1. Check code scanning alerts
2. Check secret scanning alerts
3. Review dependencies vulnerabilities
4. Suggest fixes
```

**Tools yang digunakan:**
1. `list_code_scanning_alerts`
2. `list_secret_scanning_alerts`
3. `get_file_contents` - Check dependency files
4. `search_code` - Find vulnerable patterns

---

## ✨ Best Practices

### 1. Efficient Tool Usage

**DO:**
- ✅ Gunakan `search_*` untuk finding, `list_*` untuk browsing
- ✅ Filter results dengan parameters (labels, state, date)
- ✅ Use pagination untuk large datasets
- ✅ Cache hasil search di context untuk reuse

**DON'T:**
- ❌ Jangan fetch semua data jika hanya butuh summary
- ❌ Jangan repeat calls untuk data yang sama
- ❌ Jangan ignore rate limits

### 2. Query Optimization

**Search Code:**
```
# Good - Specific
repo:owner/repo language:Python function:authenticate

# Bad - Too broad
authenticate
```

**Search Issues:**
```
# Good - Filtered
is:open label:bug created:>2026-01-01

# Bad - Unfiltered
bug
```

### 3. Context Management

Berikan context yang jelas ke Copilot:
```
# Good
"Di repository absensiQRPRO, cari semua functions yang handle QR code generation"

# Better
"Di repository FergiawanHinandi/absensiQRPRO, search file di folder /src/qr/ untuk functions yang generate QR code dengan library qrcode.js"
```

### 4. Batch Operations

Lakukan multiple operations dalam satu request:
```
Copilot, untuk PR #45:
1. Get diff
2. Get review comments
3. Get file changes
4. Analyze all together
```

### 5. Error Handling

Selalu anticipate errors:
```
Copilot, try to:
1. Get workflow logs
2. If rate limited, wait and retry
3. If not found, search for similar workflows
4. Report status clearly
```

### 6. Security Best Practices

**Token Management:**
- ✅ Gunakan fine-grained PAT dengan minimal scopes
- ✅ Rotate tokens secara berkala
- ✅ Never commit tokens to repository
- ✅ Use environment variables atau VS Code secrets

**Code Scanning:**
- ✅ Regularly check security alerts
- ✅ Enable Dependabot
- ✅ Review secret scanning alerts immediately
- ✅ Use CodeQL for custom security rules

### 7. Workflow Automation

Automate repetitive tasks:
```
# Daily standup report
Copilot, generate daily report:
- Yesterday's merged PRs
- Open issues assigned to me
- Failed CI/CD runs
- New security alerts
```

### 8. Documentation

Keep documentation updated:
```
Copilot, update README:
- List recent features from merged PRs
- Update setup instructions if changed
- Add new API endpoints from code
- Update changelog
```

---

## 🔧 Troubleshooting

### Issue: "Authentication Failed"

**Cause:** Token invalid atau expired

**Solution:**
1. Regenerate GitHub PAT
2. Update token di VS Code settings
3. Verify scopes yang diperlukan
4. Restart VS Code

---

### Issue: "Rate Limit Exceeded"

**Cause:** Too many API calls dalam waktu singkat

**Solution:**
1. Check rate limit status: `https://api.github.com/rate_limit`
2. Wait untuk reset (biasanya 1 jam)
3. Use authenticated requests (higher limit)
4. Optimize queries untuk reduce calls

**Rate Limits:**
- Authenticated: 5,000 requests/hour
- Unauthenticated: 60 requests/hour
- Search API: 30 requests/minute

---

### Issue: "Resource Not Found"

**Cause:** Repository/Issue/PR tidak exists atau no access

**Solution:**
1. Verify repository name dan owner
2. Check access permissions
3. Ensure resource exists (not deleted)
4. Verify token has required scopes

---

### Issue: "Copilot Tidak Merespons"

**Cause:** MCP Server connection issue

**Solution:**
1. Check internet connection
2. Restart VS Code
3. Disable dan enable extension
4. Check VS Code output logs:
   - View → Output → GitHub MCP Server

---

### Issue: "Incomplete Results"

**Cause:** Results melebihi page limit

**Solution:**
1. Use pagination parameters:
   ```
   page: 2, perPage: 100
   ```
2. Use `after` cursor untuk GraphQL APIs
3. Filter results untuk reduce dataset

---

### Issue: "Tool Timeout"

**Cause:** Request terlalu lama

**Solution:**
1. Break large requests ke smaller chunks
2. Use filters untuk reduce data size
3. Check GitHub status: `https://www.githubstatus.com`
4. Retry dengan exponential backoff

---

## 📚 Resources & Links

### Official Documentation
- [GitHub REST API](https://docs.github.com/en/rest)
- [GitHub GraphQL API](https://docs.github.com/en/graphql)
- [GitHub MCP Server](https://github.com/github/mcp-server)
- [VS Code Copilot](https://code.visualstudio.com/docs/copilot/overview)

### Tutorials & Guides
- [Getting Started with GitHub MCP](https://docs.github.com/en/copilot/using-github-copilot/using-github-copilot-with-extensions)
- [MCP Protocol Specification](https://modelcontextprotocol.io)
- [GitHub API Best Practices](https://docs.github.com/en/rest/guides/best-practices-for-integrators)

### Community
- [GitHub Community Forum](https://github.community/)
- [VS Code Copilot Discussions](https://github.com/microsoft/vscode-copilot/discussions)
- [GitHub MCP Examples](https://github.com/topics/github-mcp)

---

## 🎓 Advanced Tips

### 1. Custom Workflows dengan Scripts

Buat custom commands untuk common tasks:

```javascript
// .vscode/tasks.json
{
  "tasks": [
    {
      "label": "Daily Report",
      "type": "shell",
      "command": "code --ask '@workspace Generate daily GitHub report'"
    }
  ]
}
```

### 2. Integration dengan CI/CD

Trigger Copilot actions dari CI/CD (Contoh konseptual):

```yaml
# .github/workflows/copilot-review.yml
# Note: Ini adalah contoh konseptual. Verify action availability sebelum digunakan.
name: Auto Review
on: [pull_request]
jobs:
  review:
    runs-on: ubuntu-latest
    steps:
      - name: Copilot Review
        # Verify action exists before using
        uses: github/copilot-review-action@v1
```

### 3. Custom Search Queries

Save frequent searches (Contoh konseptual - verify setting availability):

```json
// .vscode/settings.json
// Note: Query syntax harus dalam bahasa English karena GitHub API syntax
{
  "github.mcp.savedQueries": {
    "criticalBugs": "is:issue is:open label:bug label:critical",
    "myPRs": "is:pr author:@me state:open",
    "recentReleases": "is:release created:>2026-01-01"
  }
}
```

### 4. Workspace Context

Leverage workspace context untuk better results:

```
@workspace Analyze all QR code related files dan suggest improvements untuk performance
```

---

## 📝 Changelog

### Version 1.0.0 (2024-01-28)
- ✅ Initial documentation
- ✅ Complete tool reference
- ✅ Common workflows
- ✅ Best practices
- ✅ Troubleshooting guide

---

## 🤝 Contributing

Dokumentasi ini adalah living document. Silakan contribute dengan:

1. Report issues atau suggestions
2. Submit PRs untuk improvements
3. Share workflows yang berguna
4. Add examples dari real use cases

---

## 📄 License

Dokumentasi ini mengikuti lisensi repository absensiQRPRO.

---

**Created via GitHub MCP Server @ VS Code Copilot** 🚀

*Last Updated: 2024-01-28*
