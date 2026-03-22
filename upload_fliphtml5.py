"""
Upload/reupload YY PDF files to FlipHTML5 via Playwright browser automation.

Determines what needs uploading based on:
- File size differences (not just larger - ANY difference triggers reupload)
- Timestamp comparison (local PDF newer than FlipHTML5 book updateTime)
- New files not yet on FlipHTML5

Usage:
    python upload_fliphtml5.py [--dry-run] [--file PATTERN]
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
PDF_DIR = os.path.join(DOCS_DIR, 'PDF')
FLIPHTML5_EMAIL = 'FlipHtml5@yadayah.com'
FLIPHTML5_PASS = 'FlipHtml5#com09'
COOKIES_FILE = '/tmp/flip_cookies.txt'

# Folder mapping: series prefix -> (folder_id, folder_name)
SERIES_FOLDERS = {
    's01': (8408251, 'An Introduction to God'),
    's02': (8413846, 'Yada Yahowah'),
    's03': (8976024, 'Observations'),
    's04': (8975969, 'Coming Home'),
    's05': (8975737, 'Babel'),
    's06': (8975461, 'Twistianity'),
    's07': (8975884, 'God Damn Religion'),
}

# State file for tracking upload metadata
STATE_FILE = os.path.join(os.path.dirname(__file__), 'fliphtml5_state.json')


def curl_json(url, method='GET', data=None, json_data=None):
    """Make a curl request and return parsed JSON."""
    cmd = ['curl', '-s', '-b', COOKIES_FILE, '-c', COOKIES_FILE]
    if method == 'POST':
        cmd.extend(['-X', 'POST'])
    if json_data is not None:
        cmd.extend(['-H', 'Content-Type: application/json', '-d', json.dumps(json_data)])
    elif data is not None:
        cmd.extend(['-d', data])
    cmd.append(url)
    result = subprocess.run(cmd, capture_output=True, text=True)
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError:
        return {'code': 'ERROR', 'msg': result.stdout[:200]}


def api_login():
    """Log in to FlipHTML5 via API, return True on success."""
    resp = curl_json(
        'https://fliphtml5.com/api/user/handle-user-login',
        method='POST',
        data=f'account={FLIPHTML5_EMAIL}&upass={FLIPHTML5_PASS.replace("#", "%23")}'
    )
    if resp.get('code') == 'OK':
        print(f"  Logged in as: {resp['data']['uName']} (uLink={resp['data']['uLink']})")
        return True
    print(f"  Login failed: {resp.get('msg', 'unknown error')}")
    return False


def get_remote_books():
    """Get all books from FlipHTML5 organized by folder."""
    books = []
    for series, (fid, fname) in SERIES_FOLDERS.items():
        resp = curl_json(
            'https://fliphtml5.com/api/folder/list-folder-data',
            method='POST',
            json_data={'folderId': fid, 'page': 1, 'size': 50}
        )
        for b in resp.get('data', {}).get('books', []):
            books.append({
                'bookId': b['bookId'],
                'title': b['title'],
                'folderId': fid,
                'folderName': fname,
                'bLink': b.get('bLink', ''),
                'pages': b.get('pages', 0),
                'updateTime': b.get('updateTime', 0),  # ms timestamp
                'newTime': b.get('newTime', 0),
            })
    return books


def get_local_pdfs():
    """Get all local YY PDF files from the PDF/ subdirectory."""
    pdfs = []
    for fpath in sorted(glob.glob(os.path.join(PDF_DIR, 'YY-*.pdf'))):
        fname = os.path.basename(fpath)
        stat = os.stat(fpath)
        # Extract series from filename: YY-s01v01-...
        parts = fname.replace('.pdf', '').split('-', 2)
        series = parts[1][:3] if len(parts) > 1 else ''  # e.g., 's01'
        # Title = filename without extension
        title = fname.replace('.pdf', '')
        pdfs.append({
            'path': fpath,
            'filename': fname,
            'title': title,
            'series': series,
            'size': stat.st_size,
            'mtime': stat.st_mtime,
            'mtime_ms': int(stat.st_mtime * 1000),
        })
    return pdfs


def load_state():
    """Load previously saved upload state (file sizes at last upload)."""
    if os.path.exists(STATE_FILE):
        with open(STATE_FILE, 'r') as f:
            return json.load(f)
    return {}


def save_state(state):
    """Save upload state."""
    with open(STATE_FILE, 'w') as f:
        json.dump(state, f, indent=2)


def match_pdf_to_book(pdf, remote_books):
    """Find matching remote book for a local PDF by title."""
    pdf_title = pdf['title']
    for book in remote_books:
        if book['title'] == pdf_title:
            return book
    # Try partial match (in case FlipHTML5 title was set differently)
    for book in remote_books:
        if pdf_title.startswith(book['title']) or book['title'].startswith(pdf_title):
            return book
    return None


def needs_upload(pdf, book, state):
    """
    Determine if a PDF needs to be uploaded/reuploaded.

    Decision criteria:
    - If no matching book exists -> new upload
    - If file size differs from last uploaded size -> reupload
    - If local PDF is newer than the FlipHTML5 book updateTime -> reupload
    """
    if book is None:
        return True, 'new (no matching book on FlipHTML5)'

    pdf_key = pdf['filename']
    prev = state.get(pdf_key, {})
    prev_size = prev.get('size', 0)
    prev_upload_time = prev.get('upload_time', 0)

    # Check file size difference
    if prev_size > 0 and prev_size != pdf['size']:
        return True, f'file size changed ({prev_size:,} -> {pdf["size"]:,} bytes)'

    # Check if local is newer than remote update time
    book_update_ms = max(book.get('updateTime', 0), book.get('newTime', 0))
    if book_update_ms > 0 and pdf['mtime_ms'] > book_update_ms:
        local_dt = datetime.fromtimestamp(pdf['mtime'], tz=timezone.utc).strftime('%Y-%m-%d %H:%M')
        remote_dt = datetime.fromtimestamp(book_update_ms / 1000, tz=timezone.utc).strftime('%Y-%m-%d %H:%M')
        return True, f'local newer ({local_dt}) than remote ({remote_dt})'

    # Check if never uploaded via this script
    if not prev:
        return True, 'no previous upload record'

    return False, 'up to date'


def build_upload_plan(local_pdfs, remote_books, state):
    """Build a plan of what needs uploading."""
    plan = []
    for pdf in local_pdfs:
        book = match_pdf_to_book(pdf, remote_books)
        should_upload, reason = needs_upload(pdf, book, state)
        series = pdf['series']
        folder_id, folder_name = SERIES_FOLDERS.get(series, (0, 'Unknown'))

        plan.append({
            'pdf': pdf,
            'book': book,
            'should_upload': should_upload,
            'reason': reason,
            'action': 'reupload' if book else 'new',
            'folder_id': folder_id,
            'folder_name': folder_name,
        })
    return plan


def upload_via_playwright(plan, state, dry_run=False):
    """Execute uploads using Playwright browser automation."""
    to_upload = [p for p in plan if p['should_upload']]

    if not to_upload:
        print('\nNothing to upload - all files are up to date.')
        return

    print(f'\n{"DRY RUN - " if dry_run else ""}Uploading {len(to_upload)} files:')
    for i, item in enumerate(to_upload):
        pdf = item['pdf']
        action = item['action']
        reason = item['reason']
        book = item['book']
        print(f'  [{i+1}/{len(to_upload)}] {action.upper()}: {pdf["title"]}')
        print(f'           Reason: {reason}')
        print(f'           Size: {pdf["size"]:,} bytes')
        if book:
            print(f'           BookId: {book["bookId"]}')

    if dry_run:
        print('\nDry run complete. No files were uploaded.')
        return

    # Write upload plan to JSON for the Node.js script
    plan_data = [{
        'path': item['pdf']['path'].replace('\\', '/'),
        'title': item['pdf']['title'],
        'filename': item['pdf']['filename'],
        'action': item['action'],
        'bookId': item['book']['bookId'] if item['book'] else None,
        'folderId': item['folder_id'],
        'folderName': item['folder_name'],
    } for item in to_upload]

    plan_path = os.path.join(os.path.dirname(__file__), '_upload_plan.json')
    results_path = os.path.join(os.path.dirname(__file__), '_upload_results.json')
    with open(plan_path, 'w', encoding='utf-8') as f:
        json.dump(plan_data, f, indent=2)

    # Generate and run the Playwright script
    script_path = os.path.join(os.path.dirname(__file__), '_pw_upload.js')
    with open(script_path, 'w', encoding='utf-8') as f:
        f.write(generate_playwright_script())

    print(f'\nRunning Playwright upload ({len(to_upload)} files)...')
    result = subprocess.run(
        ['node', script_path],
        capture_output=False, text=True, timeout=7200
    )

    # Read results and update state for successful uploads
    if os.path.exists(results_path):
        with open(results_path, 'r') as f:
            results = json.load(f)
        succeeded = [r for r in results if r.get('success')]
        failed = [r for r in results if not r.get('success')]
        print(f'\nResults: {len(succeeded)} succeeded, {len(failed)} failed')
        for r in failed:
            print(f'  FAILED: {r["title"]} - {r.get("error", "unknown")}')

        # Only update state for successful uploads
        for r in succeeded:
            fname = r['filename']
            matching = [item for item in to_upload if item['pdf']['filename'] == fname]
            if matching:
                pdf = matching[0]['pdf']
                state[fname] = {
                    'size': pdf['size'],
                    'upload_time': int(time.time() * 1000),
                    'mtime': pdf['mtime'],
                }
        save_state(state)

        # Enable TOC button via API for all successfully uploaded books
        book_ids = [r.get('bookId') for r in succeeded if r.get('bookId')]
        if book_ids:
            print(f'\nEnabling TOC button for {len(book_ids)} books via API...')
            if api_login():
                for bid in book_ids:
                    resp = curl_json(
                        'https://fliphtml5.com/api/book/update-book',
                        method='POST',
                        json_data={'bookConfig': '{"TableOfContentButtonVisible":"Show"}', 'bookId': str(bid)}
                    )
                    status = resp.get('code', '?')
                    if status != 'OK':
                        print(f'  Book {bid}: {status}')
                print(f'  TOC enabled for {len(book_ids)} books')
    else:
        print('\nWARNING: No results file found. State not updated.')


def generate_playwright_script():
    """Generate a standalone Node.js Playwright script for uploading files."""
    scripts_dir = os.path.dirname(os.path.abspath(__file__)).replace('\\', '/')
    return f'''const {{ chromium }} = require('playwright');
const fs = require('fs');
const path = require('path');

const SCRIPTS_DIR = '{scripts_dir}';
const PLAN = JSON.parse(fs.readFileSync(path.join(SCRIPTS_DIR, '_upload_plan.json'), 'utf8'));
const RESULTS_FILE = path.join(SCRIPTS_DIR, '_upload_results.json');
const results = [];

function saveResults() {{
  fs.writeFileSync(RESULTS_FILE, JSON.stringify(results, null, 2));
}}

async function waitForConversion(page, timeout = 600000) {{
  // After confirming settings, conversion starts automatically.
  // Page redirects from /re-upload to /bookinfo when done.
  const start = Date.now();
  let lastPct = '';
  while (Date.now() - start < timeout) {{
    await page.waitForTimeout(5000);
    const url = page.url();
    // Conversion complete when URL changes away from re-upload/upload
    if (url.includes('bookinfo') || (!url.includes('upload') && !url.includes('re-upload'))) {{
      console.log('    Done!');
      return true;
    }}
    // Show progress percentage
    const texts = await page.$$eval('*', els =>
      els.filter(el => el.offsetParent !== null && el.children.length === 0)
        .map(el => el.textContent?.trim())
        .filter(t => t && t.match(/^\\d+(\\.\\d+)?%$/))
    ).catch(() => []);
    const pct = texts[0] || '';
    if (pct && pct !== lastPct) {{
      process.stdout.write(`\\r    Progress: ${{pct}}  `);
      lastPct = pct;
    }}
  }}
  console.log('\\n    Conversion timed out');
  return false;
}}

(async () => {{
  const browser = await chromium.launch({{ headless: false, slowMo: 300 }});
  const page = await browser.newPage();
  page.setDefaultTimeout(60000);

  try {{
    // Login
    console.log('Logging in to FlipHTML5...');
    await page.goto('https://fliphtml5.com/login.php');
    await page.waitForTimeout(3000);
    await page.fill('input[name="login-email"], input[type="email"]', '{FLIPHTML5_EMAIL}');
    await page.fill('input[name="login-password"], input[type="password"]', '{FLIPHTML5_PASS}');
    await page.click('.cc-login-actions-btn, button[type="submit"]');
    await page.waitForURL('**/dashboard/**', {{ timeout: 30000 }});
    console.log('Logged in successfully');
    await page.waitForTimeout(3000);

    for (let i = 0; i < PLAN.length; i++) {{
      const upload = PLAN[i];
      console.log(`\\n[${{i+1}}/${{PLAN.length}}] ${{upload.action.toUpperCase()}}: ${{upload.title}}`);

      try {{
        if (upload.action === 'reupload' && upload.bookId) {{
          // Navigate to re-upload page (real URL, not hash route)
          await page.goto(`https://fliphtml5.com/edit-book/${{upload.bookId}}/re-upload`);
          await page.waitForTimeout(5000);

          // Click "Upload Files" and handle file chooser
          console.log('  Selecting file...');
          const [fileChooser] = await Promise.all([
            page.waitForEvent('filechooser', {{ timeout: 15000 }}),
            page.click('.fileAccessMainButton:has-text("Upload Files")'),
          ]);
          await fileChooser.setFiles(upload.path);
          await page.waitForTimeout(3000);

          // Handle Reupload Settings dialog
          // "Discard" = discard old settings, use new PDF's settings (imports fresh bookmarks)
          const discardBtn = page.locator('text=Discard').first();
          if (await discardBtn.isVisible({{ timeout: 5000 }}).catch(() => false)) {{
            await discardBtn.click();
            await page.waitForTimeout(1000);
          }}

          // Look for and enable any TOC/bookmark import options
          // Check all checkboxes on the dialog - log what we find
          const allCheckboxes = page.locator('input[type="checkbox"]');
          const cbCount = await allCheckboxes.count().catch(() => 0);
          if (cbCount > 0) {{
            console.log(`  Found ${{cbCount}} checkboxes in dialog`);
            for (let c = 0; c < cbCount; c++) {{
              const cb = allCheckboxes.nth(c);
              const parentText = await cb.evaluate(el => {{
                let p = el.parentElement;
                for (let i = 0; i < 3 && p; i++) {{ p = p.parentElement; }}
                return p ? p.textContent.trim().substring(0, 80) : '';
              }}).catch(() => '');
              console.log(`    Checkbox ${{c}}: "${{parentText}}" checked=${{await cb.isChecked().catch(() => '?')}}`);
              // Enable any TOC/bookmark related checkbox
              if (/table of contents|bookmark|outline|toc/i.test(parentText)) {{
                if (!await cb.isChecked().catch(() => true)) {{
                  await cb.check();
                  console.log('    -> Enabled TOC checkbox');
                }}
              }}
            }}
          }}
          // Also look for toggle switches
          const toggles = page.locator('[class*="switch"], [class*="toggle"]').filter({{ hasText: /table of contents|bookmark|toc/i }});
          if (await toggles.count().catch(() => 0) > 0) {{
            await toggles.first().click();
            console.log('  Toggled TOC switch');
          }}

          const confirmBtn = page.locator('text=Confirm').first();
          if (await confirmBtn.isVisible({{ timeout: 5000 }}).catch(() => false)) {{
            await confirmBtn.click();
            console.log('  Settings confirmed, conversion starting...');
          }}

          // Conversion runs asynchronously on FlipHTML5 - no need to wait
          console.log('  Upload submitted, conversion running in background');
          await page.waitForTimeout(2000);

          results.push({{ title: upload.title, filename: upload.filename, success: true, bookId: upload.bookId }});

        }} else {{
          // New upload - navigate to upload page
          await page.goto('https://fliphtml5.com/dashboard/home');
          await page.waitForTimeout(3000);
          await page.click('text="Upload Files"');
          await page.waitForTimeout(3000);

          // Click "Upload Files" button in the drop zone and handle file chooser
          console.log('  Selecting file...');
          const [fileChooser] = await Promise.all([
            page.waitForEvent('filechooser', {{ timeout: 15000 }}),
            page.click('.fileAccessMainButton:has-text("Upload Files")'),
          ]);
          await fileChooser.setFiles(upload.path);
          await page.waitForTimeout(3000);

          // Click Convert if visible
          const convertBtn = page.locator('text=Convert').first();
          if (await convertBtn.isVisible({{ timeout: 10000 }}).catch(() => false)) {{
            await convertBtn.click();
            console.log('  Convert clicked');
          }}

          // Conversion runs asynchronously on FlipHTML5 - no need to wait
          console.log('  Upload submitted, conversion running in background');
          await page.waitForTimeout(2000);
          results.push({{ title: upload.title, filename: upload.filename, success: true }});
        }}
      }} catch (err) {{
        console.error(`  ERROR: ${{err.message.substring(0, 150)}}`);
        results.push({{ title: upload.title, filename: upload.filename, success: false, error: err.message.substring(0, 200) }});
      }}

      saveResults();
    }}

    console.log(`\\nAll uploads processed. ${{results.filter(r => r.success).length}}/${{results.length}} succeeded.`);

  }} catch (err) {{
    console.error('Fatal error:', err.message);
  }} finally {{
    saveResults();
    await browser.close();
  }}
}})();
'''


def main():
    parser = argparse.ArgumentParser(description='Upload YY PDFs to FlipHTML5')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded without uploading')
    parser.add_argument('--file', type=str, help='Only process files matching this pattern (e.g., "s01v01")')
    parser.add_argument('--force', action='store_true', help='Force upload all files regardless of state')
    args = parser.parse_args()

    print('=== FlipHTML5 Upload Manager ===\n')

    # Step 1: Login
    print('Step 1: Logging in to FlipHTML5...')
    if not api_login():
        sys.exit(1)

    # Step 2: Get remote books
    print('\nStep 2: Getting existing books from FlipHTML5...')
    remote_books = get_remote_books()
    print(f'  Found {len(remote_books)} books on FlipHTML5')

    # Step 3: Get local PDFs
    print('\nStep 3: Scanning local PDF files...')
    local_pdfs = get_local_pdfs()
    if args.file:
        local_pdfs = [p for p in local_pdfs if args.file in p['filename']]
    print(f'  Found {len(local_pdfs)} local PDF files')

    # Step 4: Load state and build plan
    print('\nStep 4: Comparing local vs remote...')
    state = load_state()
    if args.force:
        state = {}  # Clear state to force all uploads

    plan = build_upload_plan(local_pdfs, remote_books, state)

    # Show comparison table
    to_upload = [p for p in plan if p['should_upload']]
    up_to_date = [p for p in plan if not p['should_upload']]

    print(f'\n  Need upload:  {len(to_upload)}')
    print(f'  Up to date:   {len(up_to_date)}')

    if to_upload:
        print('\n  Files to upload:')
        for item in to_upload:
            pdf = item['pdf']
            action = 'REUPLOAD' if item['book'] else 'NEW'
            print(f'    [{action}] {pdf["title"]}')
            print(f'           {item["reason"]}')
            print(f'           -> {item["folder_name"]}')

    if up_to_date and not args.file:
        print(f'\n  Files up to date: {len(up_to_date)} (skipped)')

    # Step 5: Execute uploads
    print('\nStep 5: Executing uploads...')
    upload_via_playwright(plan, state, dry_run=args.dry_run)


if __name__ == '__main__':
    main()
