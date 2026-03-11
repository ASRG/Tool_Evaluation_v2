# ASRG CSMS Tool Evaluation

Community-driven evaluation framework for automotive CSMS tools, anchored in ISO/SAE 21434.  
Hosted on the ASRG portal at [asrg.io](https://asrg.io).

---

## Repository Structure

```
asrg-csms-evaluation/
├── asrg-csms-evaluation.html   ← The evaluation table (single-file app)
├── plugin/
│   └── asrg-csms-evaluation.php  ← WordPress plugin / shortcode 
├── .github/
│   └── workflows/
│       └── deploy.yml          ← GitHub Actions CI/CD → SiteGround
└── docs/
    └── (future: scoring methodology, contributing guide)
```

---

## WordPress Setup (WPCode Shortcode)

### Option A — Install via this plugin file (recommended)

1. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**
2. Upload `plugin/asrg-csms-evaluation.php` — or zip the entire `plugin/` folder first
3. Activate the plugin
4. Create or edit a WordPress page
5. Add a **Shortcode** block and enter:
   ```
   [asrg_csms_evaluation]
   ```
6. Publish the page

> **Full-width tip:** Add this CSS to your child theme or via **Appearance → Customize → Additional CSS** to suppress the sidebar on this page:
> ```css
> .asrg-csms-full-width .entry-content { max-width: 100% !important; }
> .asrg-csms-full-width #secondary { display: none; }
> ```

### Option B — WPCode plugin (no file upload needed)

1. Install and activate the [WPCode](https://wordpress.org/plugins/insert-headers-and-footers/) plugin
2. Go to **Code Snippets → Add Snippet → PHP Snippet**
3. Paste the contents of `plugin/asrg-csms-evaluation.php`
4. Set the **Insertion** method to **Shortcode**
5. Save — WPCode will give you the shortcode to use on any page

---

## GitHub Actions CI/CD → SiteGround

Every merge to `main` automatically deploys the latest files to SiteGround via FTPS. 

### One-time setup

#### 1. Create the GitHub repository
```bash
git init
git remote add origin https://github.com/YOUR_ORG/asrg-csms-evaluation.git
git add .
git commit -m "Initial commit"
git push -u origin main 
```

#### 2. Add GitHub Secrets
Go to your repo → **Settings → Secrets and variables → Actions → New repository secret**

| Secret name | Value | Where to find it |
|---|---|---|
| `SITEGROUND_HOST` | e.g. `premium123.web-hosting.com` | SiteGround → Websites → FTP Accounts |
| `SITEGROUND_USER` | Your FTP username | SiteGround → Websites → FTP Accounts |
| `SITEGROUND_PASS` | Your FTP password | SiteGround → Websites → FTP Accounts |
| `SITEGROUND_WP_PLUGIN_PATH` | `/home/yourusername/public_html/wp-content/plugins/asrg-csms-evaluation/` | SiteGround File Manager |

#### 3. Find your SiteGround FTP credentials
1. Log into [my.siteground.com](https://my.siteground.com)
2. Go to **Websites → [your site] → FTP Accounts**
3. Note the **hostname**, **username**, and set/copy your password

#### 4. Find your WordPress plugin path
In SiteGround's **File Manager** (or via SSH):
```
/home/YOUR_USERNAME/public_html/wp-content/plugins/
```
The full `SITEGROUND_WP_PLUGIN_PATH` should be:
```
/home/YOUR_USERNAME/public_html/wp-content/plugins/asrg-csms-evaluation/
```

#### 5. Branching workflow
```
feature/my-change  →  PR to main  →  merged  →  GitHub Actions deploys automatically
```

- Work in feature branches
- Open a Pull Request to `main`
- On merge, the deploy workflow fires automatically
- Monitor runs in **Actions** tab of the repo

#### 6. (Optional) Protect the main branch
Go to **Settings → Branches → Add rule**:
- Branch name pattern: `main`
- ✅ Require a pull request before merging
- ✅ Require status checks to pass (add `Deploy to SiteGround` once it has run once)

---

## Contributing

Scores are placeholder and require community validation.  
See `docs/CONTRIBUTING.md` (coming soon) for the review process.

Community feedback on individual scores is accepted via the ASRG portal at [garage.asrg.io](https://garage.asrg.io) — authentication required.

---

## License

© Automotive Security Research Group. All rights reserved.  
Evaluation methodology and scoring rubric are proprietary to ASRG.
