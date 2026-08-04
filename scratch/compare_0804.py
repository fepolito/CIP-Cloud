import os
import filecmp

dir1 = r"C:\laragon\www\monitor.aeonium.com.br"
dir2 = r"C:\laragon\www\monitor.aeonium.com.br\scratch\prod_extracted_0804"
ignore_dirs = ['.git', 'scratch', '_backups', '.vscode', 'tools', 'scripts', 'tests']

def compare_dirs(d1, d2, rel_path=""):
    diff_report = []
    
    if not os.path.exists(d2):
        return [f"NEW IN DEV: {rel_path}"]
    
    items1 = set(os.listdir(d1))
    items2 = set(os.listdir(d2))
    
    for item in items1:
        if item in ignore_dirs and rel_path == "":
            continue
        p1 = os.path.join(d1, item)
        p2 = os.path.join(d2, item)
        rp = os.path.join(rel_path, item).replace('\\', '/')
        
        if os.path.isdir(p1):
            if os.path.isdir(p2):
                diff_report.extend(compare_dirs(p1, p2, rp))
            else:
                diff_report.append(f"DIR IN DEV, FILE IN PROD: {rp}")
        else:
            if not os.path.exists(p2):
                diff_report.append(f"NEW IN DEV (Not in PROD): {rp}")
            else:
                if not os.path.isdir(p2):
                    if not filecmp.cmp(p1, p2, shallow=False):
                        diff_report.append(f"MODIFIED: {rp}")
                else:
                    diff_report.append(f"FILE IN DEV, DIR IN PROD: {rp}")
    
    for item in items2:
        if item in ignore_dirs and rel_path == "":
            continue
        p1 = os.path.join(d1, item)
        p2 = os.path.join(d2, item)
        rp = os.path.join(rel_path, item).replace('\\', '/')
        
        if not os.path.exists(p1):
            diff_report.append(f"NEW IN PROD (Not in DEV): {rp}")
            
    return diff_report

report = compare_dirs(dir1, dir2)
with open(r"C:\laragon\www\monitor.aeonium.com.br\scratch\diff_0804.txt", "w", encoding="utf-8") as f:
    for line in sorted(report):
        f.write(line + "\n")
