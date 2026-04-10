package webroot

import (
	"fmt"
	"html/template"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

const githubURL = "https://github.com/jonathjan0397/strata-hosting-panel"

var defaultIndexTemplate = template.Must(template.New("default-index").Parse(`<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{.Domain}} is ready</title>
  <style>
    :root {
      --bg: #f4efe7;
      --ink: #1e1b18;
      --muted: #645b54;
      --panel: rgba(255, 251, 247, 0.88);
      --panel-strong: #fffaf5;
      --border: rgba(66, 45, 22, 0.12);
      --accent: #cb5a2e;
      --accent-deep: #8f3112;
      --accent-soft: #f4c9a5;
      --teal: #134e4a;
      --gold: #cf9a2e;
      --shadow: 0 32px 90px rgba(58, 33, 12, 0.18);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      background:
        radial-gradient(circle at top left, rgba(203, 90, 46, 0.28), transparent 28%),
        radial-gradient(circle at 85% 15%, rgba(19, 78, 74, 0.18), transparent 20%),
        linear-gradient(135deg, rgba(255,255,255,0.35), transparent 45%),
        var(--bg);
      color: var(--ink);
      font-family: Georgia, "Times New Roman", serif;
      padding: 28px;
    }
    .frame {
      width: min(1100px, 100%);
      margin: 0 auto;
      border: 1px solid var(--border);
      background: linear-gradient(180deg, rgba(255,255,255,0.72), rgba(255,248,241,0.94));
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .masthead {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      padding: 20px 28px;
      border-bottom: 1px solid var(--border);
      background: rgba(255, 250, 245, 0.78);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-size: 12px;
      color: var(--muted);
    }
    .brand {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 700;
      color: var(--teal);
    }
    .brand-mark {
      width: 14px;
      height: 14px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--accent), var(--gold));
      box-shadow: 0 0 0 5px rgba(203, 90, 46, 0.12);
    }
    .hero {
      display: grid;
      grid-template-columns: minmax(0, 1.15fr) minmax(280px, 0.85fr);
      gap: 28px;
      padding: 34px 28px 18px;
      align-items: stretch;
    }
    .hero-copy {
      padding: 8px 0 22px;
    }
    .eyebrow {
      display: inline-block;
      margin-bottom: 14px;
      padding: 7px 12px;
      border: 1px solid rgba(203, 90, 46, 0.25);
      background: rgba(203, 90, 46, 0.08);
      color: var(--accent-deep);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.12em;
      text-transform: uppercase;
    }
    h1 {
      margin: 0;
      max-width: 10ch;
      font-size: clamp(46px, 8vw, 88px);
      line-height: 0.92;
      letter-spacing: -0.06em;
      text-wrap: balance;
    }
    .domain {
      margin: 18px 0 0;
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 15px;
      color: var(--muted);
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }
    .domain strong {
      color: var(--teal);
      font-weight: 700;
    }
    .lede {
      margin: 20px 0 0;
      max-width: 52ch;
      color: var(--muted);
      font-size: 18px;
      line-height: 1.7;
    }
    .content {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(250px, 320px);
      gap: 28px;
      padding: 12px 28px 28px;
    }
    h2 {
      margin: 0 0 14px;
      font-size: 21px;
    }
    p, li {
      color: var(--muted);
      line-height: 1.7;
      font-size: 16px;
    }
    .steps {
      display: grid;
      gap: 14px;
      margin-top: 18px;
    }
    .step {
      display: grid;
      grid-template-columns: 44px 1fr;
      gap: 14px;
      align-items: start;
      padding: 16px 18px;
      border: 1px solid var(--border);
      background: rgba(255, 255, 255, 0.68);
    }
    .step-number {
      display: grid;
      place-items: center;
      width: 44px;
      height: 44px;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--accent), var(--accent-deep));
      color: #fff;
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 14px;
      font-weight: 700;
    }
    .step h3 {
      margin: 2px 0 6px;
      font-size: 18px;
    }
    .step p {
      margin: 0;
      font-size: 15px;
    }
    code {
      padding: 3px 8px;
      border-radius: 999px;
      background: rgba(19, 78, 74, 0.08);
      color: var(--teal);
      font-family: "Courier New", monospace;
      font-size: 14px;
    }
    .spotlight {
      position: relative;
      min-height: 100%;
      padding: 22px;
      border: 1px solid rgba(203, 90, 46, 0.18);
      background:
        linear-gradient(180deg, rgba(255,255,255,0.82), rgba(255,244,234,0.96)),
        var(--panel-strong);
    }
    .spotlight::before {
      content: "";
      position: absolute;
      inset: 16px;
      border: 1px dashed rgba(19, 78, 74, 0.18);
      pointer-events: none;
    }
    .spotlight-inner {
      position: relative;
      z-index: 1;
    }
    .pill {
      display: inline-block;
      margin-bottom: 16px;
      padding: 6px 10px;
      background: rgba(19, 78, 74, 0.08);
      color: var(--teal);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 0.1em;
      text-transform: uppercase;
    }
    .spotlight h2 {
      max-width: 12ch;
      font-size: 32px;
      line-height: 1;
      letter-spacing: -0.04em;
    }
    .spotlight p {
      margin: 14px 0 0;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-top: 20px;
    }
    .primary-link,
    .secondary-link {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 44px;
      padding: 0 16px;
      text-decoration: none;
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 13px;
      font-weight: 700;
      letter-spacing: 0.06em;
      text-transform: uppercase;
    }
    .primary-link {
      color: #fff;
      background: linear-gradient(135deg, var(--accent), var(--accent-deep));
      box-shadow: 0 14px 32px rgba(143, 49, 18, 0.18);
    }
    .secondary-link {
      color: var(--teal);
      border: 1px solid rgba(19, 78, 74, 0.22);
      background: rgba(255,255,255,0.7);
    }
    .signature {
      margin-top: 24px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 12px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
    }
    footer {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      padding: 18px 28px 24px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-family: "Trebuchet MS", Verdana, sans-serif;
      font-size: 12px;
      letter-spacing: 0.05em;
      text-transform: uppercase;
    }
    .footer-note {
      max-width: 48ch;
      line-height: 1.6;
    }
    .footer-status {
      color: var(--teal);
      font-weight: 700;
      white-space: nowrap;
    }
    @media (max-width: 880px) {
      .hero,
      .content {
        grid-template-columns: 1fr;
      }
      h1,
      .spotlight h2 {
        max-width: none;
      }
    }
    @media (max-width: 640px) {
      body {
        padding: 0;
      }
      .frame {
        width: 100%;
        min-height: 100vh;
      }
      .masthead,
      .hero,
      .content,
      footer {
        padding-left: 20px;
        padding-right: 20px;
      }
      .masthead,
      footer {
        flex-direction: column;
        align-items: flex-start;
      }
      h1 {
        font-size: clamp(40px, 16vw, 64px);
      }
      .lede {
        font-size: 17px;
      }
      .step {
        grid-template-columns: 1fr;
      }
      .step-number {
        width: 36px;
        height: 36px;
      }
      .actions {
        flex-direction: column;
      }
      .primary-link,
      .secondary-link {
        width: 100%;
      }
    }
  </style>
</head>
<body>
  <main class="frame">
    <header class="masthead">
      <div class="brand"><span class="brand-mark"></span>Strata Hosting Panel</div>
      <div>Site Provisioned Successfully</div>
    </header>
    <section class="hero">
      <div class="hero-copy">
        <div class="eyebrow">New Website Ready</div>
        <h1>Launch Something Worth Visiting.</h1>
        <p class="domain">Active domain: <strong>{{.Domain}}</strong></p>
        <p class="lede">
          Your hosting account is live, the document root exists, and this page is standing in until you publish your own site.
        </p>
      </div>
      <aside class="spotlight">
        <div class="spotlight-inner">
          <div class="pill">Powered by Strata</div>
          <h2>Built to look intentional on day one.</h2>
          <p>
            This default page confirms the website is online and gives new accounts something better than a blank directory or a generic server response.
          </p>
          <div class="actions">
            <a class="primary-link" href="` + githubURL + `" rel="noopener noreferrer">Project on GitHub</a>
            <a class="secondary-link" href="https://buymeacoffee.com/jonathan0397" rel="noopener noreferrer">Buy Me a Coffee</a>
          </div>
          <div class="signature">Open-source hosting control panel for Debian servers</div>
        </div>
      </aside>
    </section>
    <section class="content">
      <div>
        <h2>What to do next</h2>
        <div class="steps">
          <article class="step">
            <div class="step-number">01</div>
            <div>
              <h3>Upload your site files</h3>
              <p>Publish to <code>{{.DocumentRoot}}</code> and replace this page with your own <code>index.html</code> or <code>index.php</code>.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-number">02</div>
            <div>
              <h3>Use your preferred workflow</h3>
              <p>Deploy with the Strata file manager, FTP, Web Disk, Git, or an application installer depending on how you work.</p>
            </div>
          </article>
          <article class="step">
            <div class="step-number">03</div>
            <div>
              <h3>Go live</h3>
              <p>Once your files are in place, this placeholder disappears automatically because Strata never overwrites an existing homepage.</p>
            </div>
          </article>
        </div>
      </div>
      <div>
        <h2>Why this page exists</h2>
        <p>
          Fresh accounts should look provisioned, not broken. This page shows that DNS, vhost routing, and the document root are already working.
        </p>
        <p>
          It is intentionally temporary, easy to replace, and polished enough to make a newly created website feel live immediately.
        </p>
        <p>
          Server owners can customize this template in the Strata agent source and roll it out through the normal upgrade path.
        </p>
      </div>
    </section>
    <footer>
      <div class="footer-note">Managed by Strata Hosting Panel. Replace this placeholder when your actual website is ready.</div>
      <div class="footer-status">Provisioned | Online | Ready</div>
    </footer>
  </main>
</body>
</html>
`))

type defaultIndexData struct {
	Domain       string
	DocumentRoot string
}

// EnsureDefaultIndex writes a default index.html for new empty document roots.
// It intentionally does not overwrite index.html or index.php.
func EnsureDefaultIndex(username, domain, documentRoot string) error {
	cleanRoot := filepath.Clean(documentRoot)
	accountRoot := filepath.Join("/var/www", username)
	if cleanRoot != accountRoot {
		rel, err := filepath.Rel(accountRoot, cleanRoot)
		if err != nil || rel == "." || strings.HasPrefix(rel, "..") || filepath.IsAbs(rel) {
			return fmt.Errorf("document root outside account web root: %s", documentRoot)
		}
	}

	if err := os.MkdirAll(cleanRoot, 0750); err != nil {
		return fmt.Errorf("create document root: %w", err)
	}
	if out, err := exec.Command("chown", username+":www-data", cleanRoot).CombinedOutput(); err != nil {
		return fmt.Errorf("chown document root: %w: %s", err, strings.TrimSpace(string(out)))
	}
	if err := os.Chmod(cleanRoot, 0750); err != nil {
		return fmt.Errorf("chmod document root: %w", err)
	}

	if fileExists(filepath.Join(cleanRoot, "index.html")) || fileExists(filepath.Join(cleanRoot, "index.php")) {
		return nil
	}

	indexPath := filepath.Join(cleanRoot, "index.html")
	f, err := os.OpenFile(indexPath, os.O_CREATE|os.O_WRONLY|os.O_EXCL, 0644)
	if err != nil {
		if os.IsExist(err) {
			return nil
		}
		return fmt.Errorf("create default index: %w", err)
	}
	defer f.Close()

	if err := defaultIndexTemplate.Execute(f, defaultIndexData{Domain: domain, DocumentRoot: cleanRoot}); err != nil {
		return fmt.Errorf("render default index: %w", err)
	}

	if out, err := exec.Command("chown", username+":www-data", indexPath).CombinedOutput(); err != nil {
		return fmt.Errorf("chown default index: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func fileExists(path string) bool {
	_, err := os.Stat(path)
	return err == nil
}
