"""
Upload YY PDF files from docs/PDF/ to website via SFTP.

Destination: /public_html/pdf on 18.211.46.79
Only uploads files that are newer or different size than remote.

Usage:
    python upload_sftp.py [--dry-run] [--file PATTERN] [--force]
"""

import os
import sys
import glob
import stat
import argparse
import paramiko

# --- Configuration ---
SFTP_HOST = '18.211.46.79'
SFTP_USER = 'yy_jp'
SFTP_PASS = '#7#Yahowah!'
SFTP_PORT = 22
REMOTE_DIR = '/public_html/pdf'
LOCAL_DIR = r'C:\Users\Joe\Work\dev\yada\docs\PDF'


def get_local_pdfs(pattern=None):
    """Get local YY PDF files."""
    files = sorted(glob.glob(os.path.join(LOCAL_DIR, 'YY-*.pdf')))
    if pattern:
        files = [f for f in files if pattern in os.path.basename(f)]
    return files


def connect_sftp():
    """Connect to SFTP server."""
    transport = paramiko.Transport((SFTP_HOST, SFTP_PORT))
    transport.connect(username=SFTP_USER, password=SFTP_PASS)
    sftp = paramiko.SFTPClient.from_transport(transport)
    return sftp, transport


def get_remote_files(sftp):
    """Get remote file info (name -> size, mtime)."""
    remote = {}
    try:
        for entry in sftp.listdir_attr(REMOTE_DIR):
            if entry.filename.endswith('.pdf'):
                remote[entry.filename] = {
                    'size': entry.st_size,
                    'mtime': entry.st_mtime,
                }
    except FileNotFoundError:
        print(f'  Remote directory {REMOTE_DIR} not found, will create it.')
    return remote


def needs_upload(local_path, remote_info):
    """Check if local file needs uploading."""
    fname = os.path.basename(local_path)
    local_stat = os.stat(local_path)

    if fname not in remote_info:
        return True, 'new file'

    remote = remote_info[fname]
    if local_stat.st_size != remote['size']:
        return True, f'size differs (local={local_stat.st_size:,} remote={remote["size"]:,})'

    if local_stat.st_mtime > remote['mtime']:
        return True, 'local is newer'

    return False, 'up to date'


def main():
    parser = argparse.ArgumentParser(description='Upload YY PDFs to website via SFTP')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded')
    parser.add_argument('--file', type=str, help='Only upload files matching this pattern')
    parser.add_argument('--force', action='store_true', help='Upload all files regardless')
    args = parser.parse_args()

    print('=== SFTP PDF Upload ===\n')

    # Get local files
    local_files = get_local_pdfs(args.file)
    print(f'Local PDF files: {len(local_files)}')

    if not local_files:
        print('No files to upload.')
        return

    # Connect to SFTP
    print(f'\nConnecting to {SFTP_HOST}...')
    sftp, transport = connect_sftp()
    print('  Connected')

    try:
        # Ensure remote directory exists
        try:
            sftp.stat(REMOTE_DIR)
        except FileNotFoundError:
            sftp.mkdir(REMOTE_DIR)
            print(f'  Created {REMOTE_DIR}')

        # Get remote file info
        remote_info = {} if args.force else get_remote_files(sftp)
        print(f'  Remote files: {len(remote_info)}')

        # Build upload plan
        to_upload = []
        up_to_date = []
        for fpath in local_files:
            fname = os.path.basename(fpath)
            should, reason = needs_upload(fpath, remote_info)
            if should:
                to_upload.append((fpath, fname, reason))
            else:
                up_to_date.append(fname)

        print(f'\n  Need upload: {len(to_upload)}')
        print(f'  Up to date:  {len(up_to_date)}')

        if to_upload:
            print('\n  Files to upload:')
            for fpath, fname, reason in to_upload:
                size = os.path.getsize(fpath)
                print(f'    {fname} ({size:,} bytes) - {reason}')

        if args.dry_run:
            print('\nDry run complete.')
            return

        # Upload files
        if to_upload:
            print(f'\nUploading {len(to_upload)} files...')
            for i, (fpath, fname, reason) in enumerate(to_upload):
                remote_path = f'{REMOTE_DIR}/{fname}'
                size = os.path.getsize(fpath)
                print(f'  [{i+1}/{len(to_upload)}] {fname} ({size:,} bytes)...', end=' ', flush=True)
                sftp.put(fpath, remote_path)
                print('OK')

            print(f'\nDone. {len(to_upload)} files uploaded.')
        else:
            print('\nAll files are up to date.')

    finally:
        sftp.close()
        transport.close()


if __name__ == '__main__':
    main()
