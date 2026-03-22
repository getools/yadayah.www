"""
Upload/reupload YY KDP PDF files to Amazon KDP via Playwright browser automation.

Two modes:
  1. Scrape: Discover KDP book IDs from bookshelf and map to local files
  2. Upload: Reupload manuscript PDFs for books that have changed

Usage:
    python upload_kdp.py scrape              # Discover and map book IDs
    python upload_kdp.py upload [--dry-run] [--file PATTERN]  # Upload changed PDFs
    python upload_kdp.py upload --force --file s01v03         # Force reupload specific file
"""

import os
import sys
import glob
import json
import time
import argparse
import subprocess
from datetime import datetime, timezone

# --- Configuration ---
DOCS_DIR = r'C:\Users\Joe\Work\dev\yada\docs'
KDP_DIR = os.path.join(DOCS_DIR, 'KDP')
KDP_EMAIL = 'craig@winns.org'
KDP_PASS = 'cwcrystal5542cw'

SCRIPTS_DIR = os.path.dirname(os.path.abspath(__file__))
STATE_FILE = os.path.join(SCRIPTS_DIR, 'kdp_state.json')
BROWSER_DIR = os.path.join(SCRIPTS_DIR, '.kdp_browser')

# Series mapping for matching KDP titles to local files
SERIES_NAMES = {
    's01': 'An Intro to God',
    's02': 'Yada Yahowah',
    's03': 'Observations',
    's04': 'Coming Home',
    's05': 'Babel',
    's06': 'Twistianity',
    's07': 'God Damn Religion',
}


def load_state():
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE, 'r') as f:
            return json.load(f)
    return {'books': {}, 'last_scrape': None}


def save_state(state):
    with open(STATE_FILE, 'w') as f:
        json.dump(state, f, indent=2)


def get_local_kdp_pdfs(pattern=None):
    """Get local KDP PDF files (excluding cover and .KDP.NNN. variants)."""
    pdfs = []
    for fpath in sorted(glob.glob(os.path.join(KDP_DIR, 'YY-*.pdf'))):
        fname = os.path.basename(fpath)
        # Skip cover PDFs and KDP-dimensioned variants (e.g., .KDP.600.pdf)
        if '.cover.' in fname or '.KDP.' in fname:
            continue
        stat = os.stat(fpath)
        parts = fname.replace('.pdf', '').split('-', 2)
        series = parts[1][:3] if len(parts) > 1 else ''
        pdfs.append({
            'path': fpath,
            'filename': fname,
            'title': fname.replace('.pdf', ''),
            'series': series,
            'size': stat.st_size,
            'mtime': stat.st_mtime,
        })
    if pattern:
        pdfs = [p for p in pdfs if pattern in p['filename']]
    return pdfs


def generate_scrape_script():
    """Generate Playwright script to scrape KDP bookshelf for book IDs.
    Connects to running Chrome via CDP (Chrome DevTools Protocol)."""
    return f'''const {{ chromium }} = require('playwright');
const fs = require('fs');
const path = require('path');

const SCRIPTS_DIR = '{SCRIPTS_DIR.replace(chr(92), "/")}';
const RESULTS_FILE = path.join(SCRIPTS_DIR, '_kdp_scrape_results.json');

(async () => {{
  // Connect to running Chrome via CDP
  const browser = await chromium.connectOverCDP('http://localhost:9222');
  console.log('Connected to Chrome via CDP');

  const contexts = browser.contexts();
  const context = contexts[0];
  const pages = context.pages();

  // Find the KDP bookshelf tab or use first page
  let page = pages.find(p => p.url().includes('kdp.amazon.com'));
  if (!page) {{
    page = await context.newPage();
    await page.goto('https://kdp.amazon.com/en_US/bookshelf');
    await page.waitForTimeout(5000);
  }}
  console.log('Using page: ' + page.url());

  // Navigate to bookshelf if not already there
  if (!page.url().includes('bookshelf')) {{
    await page.goto('https://kdp.amazon.com/en_US/bookshelf');
    await page.waitForTimeout(5000);
  }}
  console.log('On bookshelf!');
  await page.waitForTimeout(3000);

    // Show all books (not just live)
    const viewFilter = page.locator('#podbookshelftable_view_input-option');
    if (await viewFilter.count() > 0) {{
      await viewFilter.selectOption('ALL');
      await page.waitForTimeout(3000);
    }}

    // Debug: screenshot and dump HTML structure to understand the bookshelf layout
    await page.screenshot({{ path: '{SCRIPTS_DIR.replace(chr(92), "/")}/kdp_bookshelf.png', fullPage: true }});
    console.log('Screenshot saved to kdp_bookshelf.png');

    // Dump some HTML structure for debugging
    const bodyHtml = await page.evaluate(() => {{
      // Find the main content area and get an overview of its structure
      const rows = document.querySelectorAll('tr[id]');
      const divRows = document.querySelectorAll('[class*="book"], [class*="title"], [data-book], [data-action]');
      const allIds = Array.from(document.querySelectorAll('[id]')).slice(0, 100).map(el => el.id + ' (' + el.tagName + ')');
      return JSON.stringify({{
        trWithId: rows.length,
        bookDivs: divRows.length,
        sampleIds: allIds,
        tableCount: document.querySelectorAll('table').length,
        url: window.location.href,
      }}, null, 2);
    }});
    console.log('Page structure: ' + bodyHtml);

    // Try multiple selector strategies for book rows
    const strategies = [
      'tr[id^="title-action-"]',
      'tr[id^="pod-"]',
      '.zme-indie-bookshelf-row',
      '[data-action="title-action"]',
      'table tbody tr',
      '.a-row[id]',
    ];

    let rowSelector = null;
    for (const sel of strategies) {{
      const cnt = await page.$$(sel);
      console.log(`  Selector "${{sel}}": ${{cnt.length}} elements`);
      if (cnt.length > 0 && !rowSelector) {{
        rowSelector = sel;
      }}
    }}

    const books = [];

    if (!rowSelector) {{
      console.log('WARNING: Could not find book rows. Trying to extract from page content...');

      // Last resort: extract all links that look like book edit URLs
      const links = await page.evaluate(() => {{
        return Array.from(document.querySelectorAll('a[href*="title-setup"]')).map(a => ({{
          href: a.href,
          text: a.textContent.trim().substring(0, 200),
        }}));
      }});
      console.log(`Found ${{links.length}} title-setup links`);
      for (const link of links) {{
        const match = link.href.match(/paperback\\/([^\\/]+)\\//);
        if (match) {{
          books.push({{
            bookId: match[1],
            title: link.text,
            asin: '',
            status: '',
            type: 'paperback',
          }});
          console.log(`  ${{link.text}} [${{match[1]}}]`);
        }}
      }}
    }} else {{
      console.log(`\\nUsing selector: ${{rowSelector}}`);
      const rows = await page.$$(rowSelector);

      for (const row of rows) {{
        try {{
          const id = await row.getAttribute('id') || '';
          const text = (await row.textContent()).trim();
          // Try to find a title-setup link within the row
          const link = await row.$('a[href*="title-setup"]');
          let bookId = '';
          let title = '';

          if (link) {{
            const href = await link.getAttribute('href') || '';
            const match = href.match(/paperback\\/([^\\/]+)\\//);
            if (match) bookId = match[1];
            title = (await link.textContent()).trim();
          }}

          if (!bookId && id) {{
            bookId = id.replace(/^(title-action-|pod-)/, '');
          }}

          if (!title) {{
            // Get first bold or heading text in the row
            const bold = await row.$('.a-text-bold, strong, h2, h3');
            if (bold) title = (await bold.textContent()).trim();
          }}

          if (bookId && title) {{
            books.push({{ bookId, title, asin: '', status: '', type: '' }});
            console.log(`  ${{title}} [${{bookId}}]`);
          }}
        }} catch (e) {{
          // skip
        }}
      }}
    }}

    // Pagination: check for more pages
    let hasMore = true;
    while (hasMore) {{
      const nextBtn = page.locator('.a-last:not(.a-disabled) a');
      if (await nextBtn.count() > 0) {{
        await nextBtn.click();
        await page.waitForTimeout(3000);
        const moreRows = await page.$$('a[href*="title-setup/paperback"]');
        for (const link of moreRows) {{
          const href = await link.getAttribute('href') || '';
          const match = href.match(/paperback\\/([^\\/]+)\\//);
          const title = (await link.textContent()).trim();
          if (match && title && !books.find(b => b.bookId === match[1])) {{
            books.push({{ bookId: match[1], title, asin: '', status: '', type: 'paperback' }});
            console.log(`  ${{title}} [${{match[1]}}]`);
          }}
        }}
      }} else {{
        hasMore = false;
      }}
    }}

    console.log(`\\nTotal books found: ${{books.length}}`);
    fs.writeFileSync(RESULTS_FILE, JSON.stringify(books, null, 2));
    console.log(`Results saved to ${{RESULTS_FILE}}`);

  }} catch (err) {{
    console.error('Error:', err.message);
  }} finally {{
    browser.close();  // disconnects CDP, does NOT close Chrome
  }}
}})();
'''


def generate_upload_script(uploads):
    """Generate Playwright script to upload manuscript PDFs to KDP.
    Connects to running Chrome via CDP (Chrome DevTools Protocol)."""
    uploads_json = json.dumps(uploads, indent=2)
    return f'''const {{ chromium }} = require('playwright');
const fs = require('fs');
const path = require('path');

const SCRIPTS_DIR = '{SCRIPTS_DIR.replace(chr(92), "/")}';
const RESULTS_FILE = path.join(SCRIPTS_DIR, '_kdp_upload_results.json');
const UPLOADS = {uploads_json};
const results = [];

function saveResults() {{
  fs.writeFileSync(RESULTS_FILE, JSON.stringify(results, null, 2));
}}

async function waitForUploadSuccess(page, timeout = 1200000) {{
  const start = Date.now();
  while (Date.now() - start < timeout) {{
    const success = await page.$('#data-print-book-publisher-interior-file-upload-success');
    if (success) {{
      const visible = await success.isVisible();
      if (visible) {{
        console.log('    Upload processing complete');
        return true;
      }}
    }}
    // Check for error alerts
    const errors = await page.$$('.a-alert-error');
    for (const error of errors) {{
      if (await error.isVisible()) {{
        const errText = await error.textContent();
        console.log('    Upload error: ' + errText.trim().substring(0, 200));
        return false;
      }}
    }}
    await page.waitForTimeout(5000);
    const elapsed = Math.round((Date.now() - start) / 1000);
    if (elapsed % 30 === 0) {{
      process.stdout.write(`\\r    Waiting for upload processing... ${{elapsed}}s  `);
    }}
  }}
  console.log('\\n    Upload timed out');
  return false;
}}

(async () => {{
  // Connect to running Chrome via CDP
  const browser = await chromium.connectOverCDP('http://localhost:9222');
  console.log('Connected to Chrome via CDP');

  const contexts = browser.contexts();
  const context = contexts[0];
  const pages = context.pages();

  // Find KDP tab or create new page
  let page = pages.find(p => p.url().includes('kdp.amazon.com'));
  if (!page) {{
    page = await context.newPage();
  }}
  page.setDefaultTimeout(60000);

  try {{
    // Verify we're logged in
    if (!page.url().includes('kdp.amazon.com')) {{
      await page.goto('https://kdp.amazon.com/en_US/bookshelf');
      await page.waitForTimeout(5000);
    }}

    if (!page.url().includes('kdp.amazon.com')) {{
      console.log('Not logged in. Please log in to KDP in Chrome first.');
      throw new Error('Not logged in');
    }}
    console.log('Logged in at: ' + page.url());

    for (let i = 0; i < UPLOADS.length; i++) {{
      const upload = UPLOADS[i];
      console.log(`\\n[${{i+1}}/${{UPLOADS.length}}] Uploading: ${{upload.title}}`);
      console.log(`  Book ID: ${{upload.bookId}}`);
      console.log(`  File: ${{upload.filePath}}`);

      try {{
        // Navigate to the book's Content tab
        const contentUrl = `https://kdp.amazon.com/en_US/title-setup/paperback/${{upload.bookId}}/content`;
        console.log('  Navigating to content page...');
        await page.goto(contentUrl);
        await page.waitForTimeout(5000);

        // Take debug screenshot
        await page.screenshot({{ path: SCRIPTS_DIR + '/kdp_content_' + upload.bookId + '.png' }});

        // Find the manuscript upload input
        // Try the direct file input first
        const fileInput = page.locator('input[type="file"]');
        if (await fileInput.count() > 0) {{
          console.log('  Setting manuscript file via input...');
          await fileInput.first().setInputFiles(upload.filePath);
        }} else {{
          // Fall back to clicking the browse button and handling file chooser
          console.log('  Clicking upload button...');
          const [fileChooser] = await Promise.all([
            page.waitForEvent('filechooser', {{ timeout: 15000 }}),
            page.click('#data-print-book-publisher-interior-file-upload-browse-button-announce'),
          ]);
          await fileChooser.setFiles(upload.filePath);
        }}

        console.log('  File selected, waiting for processing...');
        const uploadOk = await waitForUploadSuccess(page);

        if (uploadOk) {{
          // Save the content page (don't publish - just save draft)
          console.log('  Saving...');
          const saveBtn = page.locator('#save-announce');
          if (await saveBtn.isVisible({{ timeout: 5000 }}).catch(() => false)) {{
            await saveBtn.click();
            await page.waitForTimeout(10000);
            console.log('  Saved successfully');
          }}
          results.push({{ title: upload.title, filename: upload.filename, bookId: upload.bookId, success: true }});
        }} else {{
          results.push({{ title: upload.title, filename: upload.filename, bookId: upload.bookId, success: false, error: 'Upload processing failed or timed out' }});
        }}

      }} catch (err) {{
        console.error(`  ERROR: ${{err.message.substring(0, 200)}}`);
        results.push({{ title: upload.title, filename: upload.filename, bookId: upload.bookId, success: false, error: err.message.substring(0, 200) }});
      }}

      saveResults();
    }}

    console.log(`\\nAll uploads processed. ${{results.filter(r => r.success).length}}/${{results.length}} succeeded.`);

  }} catch (err) {{
    console.error('Fatal error:', err.message);
  }} finally {{
    saveResults();
    browser.close();  // disconnects CDP, does NOT close Chrome
  }}
}})();
'''


def match_local_to_remote(local_pdfs, remote_books):
    """Match local PDF files to remote KDP books by title similarity."""
    matches = []
    for pdf in local_pdfs:
        pdf_title = pdf['title']
        best_match = None
        best_score = 0

        for book in remote_books:
            remote_title = book.get('title', '')
            # Try various matching strategies
            # Exact substring match
            if pdf_title in remote_title or remote_title in pdf_title:
                score = len(remote_title)
                if score > best_score:
                    best_score = score
                    best_match = book
                continue

            # Match by series name + volume theme
            # e.g., "YY-s01v03-An Intro to God-Towrah Mizmowr" -> look for "Towrah Mizmowr" in remote title
            parts = pdf_title.split('-', 2)
            if len(parts) >= 3:
                theme = parts[2].split('-', 1)[-1] if '-' in parts[2] else parts[2]
                if theme.lower() in remote_title.lower():
                    score = len(theme)
                    if score > best_score:
                        best_score = score
                        best_match = book

        matches.append({
            'pdf': pdf,
            'book': best_match,
        })

    return matches


def generate_login_script():
    """Generate Playwright script that opens KDP for manual login."""
    return f'''const {{ chromium }} = require('playwright');
const BROWSER_DIR = '{os.path.join(SCRIPTS_DIR, ".kdp_browser").replace(chr(92), "/")}';

(async () => {{
  const context = await chromium.launchPersistentContext(BROWSER_DIR, {{
    headless: false,
    viewport: {{ width: 1280, height: 900 }},
  }});
  const page = context.pages()[0] || await context.newPage();

  console.log('Opening KDP login page...');
  console.log('Please log in manually. The browser will stay open for 3 minutes.');
  console.log('Once you see the bookshelf, press Ctrl+C or just wait.\\n');

  await page.goto('https://kdp.amazon.com/en_US/bookshelf');

  // Poll until we reach the bookshelf or timeout
  const start = Date.now();
  while (Date.now() - start < 180000) {{
    await page.waitForTimeout(3000);
    if (page.url().includes('bookshelf')) {{
      console.log('\\nBookshelf reached! Session saved.');
      break;
    }}
    console.log('  Waiting for login... (' + page.url().substring(0, 60) + ')');
  }}

  await page.waitForTimeout(2000);
  await context.close();
  console.log('Browser closed. Session cookies saved to .kdp_browser/');
}})();
'''


def cmd_login(args):
    """Open browser for manual KDP login."""
    print('=== KDP Manual Login ===\n')
    print('A browser will open. Log in to KDP manually (complete MFA if needed).')
    print('The session will be saved for future scrape/upload commands.\n')

    script_path = os.path.join(SCRIPTS_DIR, '_kdp_login.js')
    with open(script_path, 'w', encoding='utf-8') as f:
        f.write(generate_login_script())

    subprocess.run(
        ['node', script_path],
        capture_output=False, text=True, timeout=300
    )
    print('\nDone. Now run: python upload_kdp.py scrape')


def cmd_scrape(args):
    """Scrape KDP bookshelf to discover book IDs."""
    print('=== KDP Bookshelf Scraper ===\n')

    script_path = os.path.join(SCRIPTS_DIR, '_kdp_scrape.js')
    results_path = os.path.join(SCRIPTS_DIR, '_kdp_scrape_results.json')

    with open(script_path, 'w', encoding='utf-8') as f:
        f.write(generate_scrape_script())

    print('Running Playwright scraper (browser will open)...')
    print('NOTE: If OTP/captcha is required, complete it manually in the browser.\n')

    result = subprocess.run(
        ['node', script_path],
        capture_output=False, text=True, timeout=300
    )

    if not os.path.exists(results_path):
        print('\nERROR: No results file. Scrape may have failed.')
        return

    with open(results_path, 'r') as f:
        books = json.load(f)

    print(f'\nFound {len(books)} books on KDP')

    # Update state with book mappings
    state = load_state()

    # Try to auto-match to local files
    local_pdfs = get_local_kdp_pdfs()
    matches = match_local_to_remote(local_pdfs, books)

    print(f'\nAuto-matched {sum(1 for m in matches if m["book"])} of {len(local_pdfs)} local files:')
    for m in matches:
        pdf = m['pdf']
        book = m['book']
        if book:
            state['books'][pdf['filename']] = {
                'bookId': book['bookId'],
                'title': book['title'],
                'asin': book.get('asin', ''),
                'type': book.get('type', ''),
            }
            print(f'  {pdf["title"]}')
            print(f'    -> {book["title"]} [{book["bookId"]}]')
        else:
            print(f'  {pdf["title"]}')
            print(f'    -> NO MATCH')

    state['last_scrape'] = datetime.now(timezone.utc).isoformat()
    state['remote_books'] = books
    save_state(state)
    print(f'\nState saved to {STATE_FILE}')


def get_kdp_map_from_db():
    """Get KDP ID -> PDF filename mapping from yy_volume table."""
    result = subprocess.run(
        ['docker', 'exec', '-i', 'yada-postgres', 'psql', '-U', 'postgres', '-d', 'yada',
         '-t', '-A', '-c',
         "SELECT volume_kdp_id, volume_file FROM yy_volume WHERE volume_kdp_id IS NOT NULL"],
        capture_output=True, text=True, timeout=10
    )
    mapping = {}
    for line in result.stdout.strip().split('\n'):
        if '|' in line:
            kdp_id, vol_file = line.split('|', 1)
            mapping[vol_file.strip()] = kdp_id.strip()
    return mapping


def cmd_upload(args):
    """Upload changed KDP PDFs."""
    print('=== KDP Manuscript Uploader ===\n')

    # Get KDP ID mapping from database
    kdp_map = get_kdp_map_from_db()
    if not kdp_map:
        print('ERROR: No KDP IDs found in yy_volume table.')
        sys.exit(1)
    print(f'Found {len(kdp_map)} books with KDP IDs in database')

    state = load_state()
    local_pdfs = get_local_kdp_pdfs(args.file)
    print(f'Found {len(local_pdfs)} local KDP PDFs')

    # Determine what needs uploading
    to_upload = []
    for pdf in local_pdfs:
        # Match by volume_file (base name without .pdf)
        base = pdf['filename'].replace('.pdf', '')
        kdp_id = kdp_map.get(base)
        if not kdp_id:
            print(f'  SKIP (no KDP ID): {pdf["title"]}')
            continue

        book_state = state.get('books', {}).get(pdf['filename'], {})
        prev_size = book_state.get('uploaded_size', 0)
        prev_time = book_state.get('upload_time', 0)

        if args.force:
            reason = 'forced'
        elif prev_size == 0:
            reason = 'never uploaded via this script'
        elif prev_size != pdf['size']:
            reason = f'file size changed ({prev_size:,} -> {pdf["size"]:,} bytes)'
        elif pdf['mtime'] * 1000 > prev_time:
            reason = 'local file is newer'
        else:
            print(f'  UP TO DATE: {pdf["title"]}')
            continue

        to_upload.append({
            'pdf': pdf,
            'bookId': kdp_id,
            'remoteTitle': base,
            'reason': reason,
        })

    if not to_upload:
        print('\nNothing to upload - all files are up to date.')
        return

    print(f'\nFiles to upload: {len(to_upload)}')
    for item in to_upload:
        print(f'  [{item["bookId"]}] {item["pdf"]["title"]}')
        print(f'           Reason: {item["reason"]}')
        print(f'           Remote: {item["remoteTitle"]}')

    if args.dry_run:
        print('\nDry run complete. No files were uploaded.')
        return

    # Build upload plan for Playwright
    uploads = [{
        'title': item['pdf']['title'],
        'filename': item['pdf']['filename'],
        'filePath': item['pdf']['path'].replace('\\', '/'),
        'bookId': item['bookId'],
    } for item in to_upload]

    script_path = os.path.join(SCRIPTS_DIR, '_kdp_upload.js')
    results_path = os.path.join(SCRIPTS_DIR, '_kdp_upload_results.json')

    with open(script_path, 'w', encoding='utf-8') as f:
        f.write(generate_upload_script(uploads))

    print(f'\nRunning Playwright upload ({len(uploads)} files)...')
    print('NOTE: If OTP/captcha is required, complete it manually in the browser.\n')

    result = subprocess.run(
        ['node', script_path],
        capture_output=False, text=True, timeout=7200
    )

    # Process results
    if os.path.exists(results_path):
        with open(results_path, 'r') as f:
            results = json.load(f)

        succeeded = [r for r in results if r.get('success')]
        failed = [r for r in results if not r.get('success')]
        print(f'\nResults: {len(succeeded)} succeeded, {len(failed)} failed')

        for r in failed:
            print(f'  FAILED: {r["title"]} - {r.get("error", "unknown")}')

        # Update state for successful uploads
        if 'books' not in state:
            state['books'] = {}
        for r in succeeded:
            fname = r['filename']
            matching = [item for item in to_upload if item['pdf']['filename'] == fname]
            if matching:
                state['books'][fname] = {
                    'bookId': r['bookId'],
                    'uploaded_size': matching[0]['pdf']['size'],
                    'upload_time': int(time.time() * 1000),
                }
        save_state(state)
    else:
        print('\nWARNING: No results file found. State not updated.')


def main():
    parser = argparse.ArgumentParser(description='Amazon KDP Upload Manager')
    subparsers = parser.add_subparsers(dest='command', help='Command to run')

    # Login command
    login_parser = subparsers.add_parser('login', help='Open browser for manual KDP login')

    # Scrape command
    scrape_parser = subparsers.add_parser('scrape', help='Scrape KDP bookshelf for book IDs')

    # Upload command
    upload_parser = subparsers.add_parser('upload', help='Upload changed manuscript PDFs')
    upload_parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded')
    upload_parser.add_argument('--file', type=str, help='Only process files matching this pattern')
    upload_parser.add_argument('--force', action='store_true', help='Force upload regardless of state')

    args = parser.parse_args()

    if args.command == 'login':
        cmd_login(args)
    elif args.command == 'scrape':
        cmd_scrape(args)
    elif args.command == 'upload':
        cmd_upload(args)
    else:
        parser.print_help()


if __name__ == '__main__':
    main()
