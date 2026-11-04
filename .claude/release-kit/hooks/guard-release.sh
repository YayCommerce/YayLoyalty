#!/usr/bin/env bash
# PreToolUse hook for the Bash tool: blocks commands that would publish, upload or rewrite
# history, so the release kit's hard rule holds even if a skill or a person asks for it.
# Installed only with approval (`yr kit hook-install`). Exit 2 = blocked; stderr says why.
#
# Blocks: svn writes (commit/import/remote cp/mv/rm/mkdir), git push --force/-f/--delete/+ref/:ref/
# --mirror, git tag -d/-f, git branch -D, scp/sftp/ftp/lftp, ssh, rsync to host:path, curl/wget
# uploads (-T, --upload-file, -F, --data, POST/PUT), gh release create/upload, mail senders.
php -r '
$in = json_decode(stream_get_contents(STDIN), true);
$c  = (string)($in["tool_input"]["command"] ?? "");
if ($c === "") exit(0);
$rules = [
  "/\bsvn\s+(ci|commit|import)\b/" => "SVN commits publish to WordPress.org; the manager uploads releases by hand",
  "/\bsvn\s+(cp|copy|mv|move|rm|delete|mkdir)\b[^|;&]*\w+:\/\//" => "remote SVN changes publish to WordPress.org",
  "/\bgit\s+push\b[^|;&]*(\s-f\b|\s--force\b|--force-with-lease|\s--delete\b|\s-d\b|\s\+[\w\/.-]+|\s:[\w\/.-]+|--mirror|--prune)/" => "force-push / remote deletion is never allowed",
  "/\bgit\s+tag\b[^|;&]*(\s-d\b|\s--delete\b|\s-f\b|\s--force\b)/" => "release tags are never moved or deleted",
  "/\bgit\s+branch\b[^|;&]*\s-D\b/" => "force-deleting branches is not allowed (use yr git delete-branch, which refuses unmerged work)",
  "/(^|[\s;&|(])(scp|sftp|ftp|lftp|ncftp)\s/" => "file transfers to sites are not allowed",
  "/(^|[\s;&|(])ssh\s+[^-]/" => "SSH to sites is not allowed",
  "/\brsync\b[^|;&]*\s[\w.@-]+:[^\s]*/" => "rsync to a remote host is not allowed",
  "/\bcurl\b[^|;&]*(\s-T\b|--upload-file|\s-F\b|--form\b|\s-d\b|--data(-\w+)?\b|-X\s*(POST|PUT|PATCH|DELETE))/i" => "HTTP uploads/posts are not allowed",
  "/\bwget\b[^|;&]*--(post|method)/i" => "HTTP uploads/posts are not allowed",
  "/\bgh\s+release\s+(create|upload|edit|delete)\b/" => "publishing GitHub releases is not part of this workflow",
  "/(^|[\s;&|(])(sendmail|mailx|mutt|msmtp)\b|\bmail\s+-s\b/" => "the kit never emails customers",
];
foreach ($rules as $re => $why) {
  if (preg_match($re, $c)) {
    fwrite(STDERR, "Blocked by the release kit guard: $why.\nCommand: $c\nIf a person really needs this, they run it themselves outside Claude.\n");
    exit(2);
  }
}
exit(0);
'
