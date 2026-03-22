"""
Deploy web app files to yadayah.com production server via SSH/SCP.

Uploads public/ and api/ to /opt/yada-www/ on 187.77.13.242.
Docker maps ./public -> /var/www/html and ./api -> /var/www/html/api.

Usage:
    python deploy_hostinger.py [--dry-run] [--force]
"""

import os
import sys
import subprocess
import argparse

# --- Configuration ---
SSH_HOST = '187.77.13.242'
SSH_USER = 'root'
REMOTE_BASE = '/opt/yada-www'
LOCAL_BASE = os.path.dirname(os.path.abspath(__file__))

# Directories to sync: (local_subdir, remote_subdir)
# public/ -> public/, public/api/ -> api/ (Docker volume overlay)
SYNC_DIRS = [
    ('public', 'public'),
    (os.path.join('public', 'api'), 'api'),
]

# File extensions to include
INCLUDE_EXT = {'.html', '.php', '.css', '.js', '.json', '.png', '.jpg', '.jpeg', '.gif', '.svg', '.woff2', '.ico'}

# Files/dirs to skip
SKIP_NAMES = {'node_modules', '.git', '__pycache__', '.DS_Store', 'Thumbs.db'}


def ssh_cmd(cmd):
    """Run a command on the remote server via SSH."""
    result = subprocess.run(
        ['ssh', f'{SSH_USER}@{SSH_HOST}', cmd],
        capture_output=True, text=True, timeout=30
    )
    return result.stdout.strip()


def get_remote_file_info(remote_dir):
    """Get dict of remote files: relative_path -> size."""
    output = ssh_cmd(f'find {remote_dir} -type f -printf "%P\\t%s\\n" 2>/dev/null')
    files = {}
    for line in output.splitlines():
        if '\t' in line:
            rel_path, size = line.split('\t', 1)
            files[rel_path] = int(size)
    return files


def get_local_files(local_dir):
    """Get dict of local files: relative_path -> (size, abs_path)."""
    files = {}
    for root, dirs, filenames in os.walk(local_dir):
        dirs[:] = [d for d in dirs if d not in SKIP_NAMES]
        for fname in filenames:
            if fname in SKIP_NAMES:
                continue
            ext = os.path.splitext(fname)[1].lower()
            if ext not in INCLUDE_EXT:
                continue
            abs_path = os.path.join(root, fname)
            rel_path = os.path.relpath(abs_path, local_dir).replace('\\', '/')
            st = os.stat(abs_path)
            files[rel_path] = (st.st_size, abs_path)
    return files


def main():
    parser = argparse.ArgumentParser(description='Deploy web app to yadayah.com via SSH')
    parser.add_argument('--dry-run', action='store_true', help='Show what would be uploaded')
    parser.add_argument('--force', action='store_true', help='Upload all files regardless')
    args = parser.parse_args()

    print('=== Deploy to Production ===\n')
    print(f'Server: {SSH_USER}@{SSH_HOST}')
    print(f'Remote: {REMOTE_BASE}\n')

    total_uploaded = 0
    total_skipped = 0

    for local_subdir, remote_subdir in SYNC_DIRS:
        local_dir = os.path.join(LOCAL_BASE, local_subdir)
        remote_dir = REMOTE_BASE + '/' + remote_subdir

        print(f'--- {local_subdir}/ -> {remote_dir}/ ---')

        local_files = get_local_files(local_dir)
        print(f'  Local files: {len(local_files)}')

        if args.force:
            remote_files = {}
        else:
            remote_files = get_remote_file_info(remote_dir)
            print(f'  Remote files: {len(remote_files)}')

        to_upload = []
        for rel_path, (lsize, abs_path) in sorted(local_files.items()):
            if rel_path in remote_files:
                rsize = remote_files[rel_path]
                if lsize == rsize and not args.force:
                    total_skipped += 1
                    continue
                reason = f'size {lsize:,} vs {rsize:,}'
            else:
                reason = 'new'
            to_upload.append((rel_path, abs_path, lsize, reason))

        if to_upload:
            print(f'  To upload: {len(to_upload)}')
            for rel_path, abs_path, size, reason in to_upload:
                print(f'    {rel_path} ({size:,} bytes) [{reason}]')

            if not args.dry_run:
                for i, (rel_path, abs_path, size, reason) in enumerate(to_upload):
                    remote_path = remote_dir + '/' + rel_path
                    remote_parent = '/'.join(remote_path.split('/')[:-1])
                    ssh_cmd(f'mkdir -p {remote_parent}')
                    print(f'    [{i+1}/{len(to_upload)}] {rel_path}...', end=' ', flush=True)
                    subprocess.run(
                        ['scp', '-q', abs_path, f'{SSH_USER}@{SSH_HOST}:{remote_path}'],
                        check=True, timeout=60
                    )
                    print('OK')
                total_uploaded += len(to_upload)
        else:
            print('  All up to date')
            total_skipped += len(local_files)

        print()

    if args.dry_run:
        print('Dry run complete.')
    else:
        print(f'Done. {total_uploaded} files uploaded, {total_skipped} up to date.')


if __name__ == '__main__':
    main()
