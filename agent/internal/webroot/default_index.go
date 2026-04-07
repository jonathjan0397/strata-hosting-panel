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
      color-scheme: light dark;
      --bg: #f4f5f7;
      --panel: #ffffff;
      --ink: #1f2937;
      --muted: #6b7280;
      --border: #d9dee7;
      --accent: #2563eb;
      --accent-dark: #1e40af;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      min-height: 100vh;
      display: grid;
      place-items: center;
      background:
        linear-gradient(135deg, rgba(37,99,235,.08), transparent 35%),
        linear-gradient(315deg, rgba(15,23,42,.08), transparent 35%),
        var(--bg);
      color: var(--ink);
      font-family: "DejaVu Sans", Verdana, Arial, sans-serif;
    }
    main {
      width: min(920px, calc(100% - 32px));
      border: 1px solid var(--border);
      background: var(--panel);
      box-shadow: 0 18px 55px rgba(15,23,42,.16);
    }
    .hero {
      padding: 34px 38px;
      border-bottom: 6px solid var(--accent);
      background: linear-gradient(180deg, #fff, #f7f8fb);
    }
    h1 {
      margin: 0 0 10px;
      font-size: clamp(28px, 4vw, 44px);
      letter-spacing: -.04em;
    }
    .subtitle {
      margin: 0;
      color: var(--muted);
      font-size: 17px;
      line-height: 1.6;
    }
    .content {
      display: grid;
      grid-template-columns: 1.2fr .8fr;
      gap: 28px;
      padding: 32px 38px 38px;
    }
    h2 {
      margin: 0 0 12px;
      font-size: 18px;
    }
    p, li {
      color: var(--muted);
      line-height: 1.65;
      font-size: 15px;
    }
    code {
      padding: 2px 6px;
      border-radius: 4px;
      background: #eef2ff;
      color: #3730a3;
    }
    .powered {
      border: 1px solid var(--border);
      background: #f8fafc;
      padding: 22px;
    }
    .powered a {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 12px;
      color: #fff;
      background: var(--accent);
      padding: 10px 14px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 700;
    }
    .powered a:hover { background: var(--accent-dark); }
    footer {
      padding: 16px 38px;
      border-top: 1px solid var(--border);
      color: var(--muted);
      font-size: 13px;
      background: #f8fafc;
    }
    @media (max-width: 720px) {
      .content { grid-template-columns: 1fr; padding: 26px; }
      .hero { padding: 28px 26px; }
      footer { padding: 16px 26px; }
    }
  </style>
</head>
<body>
  <main>
    <section class="hero">
      <h1>It works!</h1>
      <p class="subtitle">The website for <strong>{{.Domain}}</strong> is online and ready for content.</p>
    </section>
    <section class="content">
      <div>
        <h2>Default site page</h2>
        <p>This page is shown because the document root has been created, but no site files have been uploaded yet.</p>
        <ul>
          <li>Upload your website files into <code>{{.DocumentRoot}}</code>.</li>
          <li>Replace this file with your own <code>index.html</code> or <code>index.php</code>.</li>
          <li>Use the Strata file manager, FTP, Git, or app installer to publish your site.</li>
        </ul>
      </div>
      <aside class="powered">
        <h2>Powered by Strata Hosting Panel</h2>
        <p>Open-source hosting control panel for Debian servers.</p>
        <a href="` + githubURL + `" rel="noopener noreferrer">View on GitHub</a>
      </aside>
    </section>
    <footer>Strata Hosting Panel managed default page. Remove or replace this file when you publish your site.</footer>
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
