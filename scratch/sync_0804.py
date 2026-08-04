import os
import shutil
import filecmp

dir1 = r"C:\laragon\www\monitor.aeonium.com.br"
dir2 = r"C:\laragon\www\monitor.aeonium.com.br\scratch\prod_extracted_0804"

ignore_files_or_dirs = [
    'config/database.php',
    'config/app.php',
    'api/config/env.php',
    '.htaccess',
    'api/.htaccess',
    'app/.htaccess',
    'error_log',
    'api/sync/error_log',
    'api/v1/cip/error_log',
    '.last_deploy',
    'storage/install.lock',
    'storage/sessions',
    '.git',
    'scratch',
    '_backups',
    '.vscode',
    'tools',
    'scripts',
    'tests',
    'api/v1/cip/sync.php' # Ignore to prevent overwriting the Phase 2 code we just wrote!
]

def is_ignored(rel_path):
    rp = rel_path.replace('\\', '/')
    for ig in ignore_files_or_dirs:
        if rp == ig or rp.startswith(ig + '/'):
            return True
        # For error_log anywhere
        if rp.endswith('/error_log') or rp == 'error_log':
            return True
    return False

def sync_dirs(d_dest, d_src, rel_path=""):
    items2 = set(os.listdir(d_src))
    for item in items2:
        rp = os.path.join(rel_path, item).replace('\\', '/')
        if is_ignored(rp):
            continue
            
        p_src = os.path.join(d_src, item)
        p_dest = os.path.join(d_dest, item)
        
        if os.path.isdir(p_src):
            if not os.path.exists(p_dest):
                os.makedirs(p_dest)
            sync_dirs(p_dest, p_src, rp)
        else:
            if not os.path.exists(p_dest) or not filecmp.cmp(p_src, p_dest, shallow=False):
                print(f"Syncing: {rp}")
                shutil.copy2(p_src, p_dest)

sync_dirs(dir1, dir2)
print("Sync complete.")
