<?php
date_default_timezone_set('Asia/Jakarta'); // Forces system timeline to UTC+7 execution space
define('DB_DIR', __DIR__ . '/wikidata_local_db');
define('SUPERUSER_ID', 'SET UP YOURS!!');
$app_key = 'your-super-secret-app-wide-key!'; // Change this in production
$cipher = 'AES-256-CBC';
$iv = substr(hash('sha256', 'static-iv-for-single-file-prototype'), 0, 16);


if (!file_exists(DB_DIR)) {
    mkdir(DB_DIR, 0755, true);
    file_put_contents(DB_DIR . '/.htaccess', "Deny from all");
}

if (!isset($_COOKIE['wiki_user_id'])) {
    $visitorId = 'u_' . bin2hex(random_bytes(8));
    setcookie('wiki_user_id', $visitorId, time() + (86400 * 365 * 25), "/");
    $_COOKIE['wiki_user_id'] = $visitorId;
}
$currentUserId = $_COOKIE['wiki_user_id'];
$currentUserIP = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$currentUserUA = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

function db_load_json($filename, $default = []) {
    $path = DB_DIR . '/' . $filename;
    if (!file_exists($path)) return $default;
    return json_decode(file_get_contents($path), true) ?? $default;
}

function db_save_json($filename, $data) {
    file_put_contents(DB_DIR . '/' . $filename, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
}

function db_get_chunk_filename($itemId) {
    return "items_chunk_" . floor(intval($itemId) / 100) . ".json";
}

function db_get_item($itemId) {
    $chunk = db_load_json(db_get_chunk_filename($itemId));
    return $chunk[$itemId] ?? null;
}

function db_save_item($itemId, $itemData) {
    $filename = db_get_chunk_filename($itemId);
    $chunk = db_load_json($filename);
    $chunk[$itemId] = $itemData;
    db_save_json($filename, $chunk);
}

$index = db_load_json('index.json', ['next_item_id' => 1, 'labels' => [], 'registry' => [], 'properties' => [], 'backlinks' => [], 'lists' => []]);
$users = db_load_json('users.json', []);
$forum = db_load_json('forum_threads.json', []);
$logs  = db_load_json('logs.json', []);

if (!isset($index['registry'])) {
    $index['registry'] = [];
    foreach ($index['labels'] as $lowLabel => $id) {
        $index['registry'][$id] = ['label' => ucwords($lowLabel), 'timestamp' => time()];
    }
    db_save_json('index.json', $index);
}

if (!isset($users[$currentUserId])) {
    $users[$currentUserId] = ['id' => $currentUserId, 'ip' => $currentUserIP, 'ua' => $currentUserUA, 'banned' => false, 'ban_until' => 0, 'lists' => [], 'messages' => []];
} else {
    $users[$currentUserId]['ip'] = $currentUserIP;
    $users[$currentUserId]['ua'] = $currentUserUA;
}
db_save_json('users.json', $users);




if (!isset($users[SUPERUSER_ID])) {
    $users[SUPERUSER_ID] = ['id' => SUPERUSER_ID, 'ip' => '127.0.0.1', 'ua' => 'System Engine', 'banned' => false, 'ban_until' => 0, 'lists' => [], 'messages' => []];
    db_save_json('users.json', $users);
}

$isBanned = false;
if (!empty($users[$currentUserId]['banned'])) {
    if ($users[$currentUserId]['ban_until'] == -1 || $users[$currentUserId]['ban_until'] > time()) {
        $isBanned = true;
    }
}

function log_activity($userId, $action, $detail) {
    global $logs;
    array_unshift($logs, ['timestamp' => time(), 'user_id' => $userId, 'action' => $action, 'detail' => $detail]);
    if (count($logs) > 2000) array_pop($logs);
    db_save_json('logs.json', $logs);
}

// ASYNCHRONOUS DATA PIPELINE AND AUTOCOMPLETE ROUTER
if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    $apiAction = $_GET['api'];
    
    if ($apiAction === 'load_items') {
        $offset = intval($_GET['offset'] ?? 0);
        $limit = 10;
        $registry = $index['registry'];
        uasort($registry, function($a, $b) { return $b['timestamp'] <=> $a['timestamp']; });
        $slice = array_slice($registry, $offset, $limit, true);
        $output = [];
        foreach ($slice as $id => $meta) {
            $output[] = ['id' => $id, 'label' => $meta['label']];
        }
        echo json_encode($output);
        exit;
    }

    if ($apiAction === 'load_logs' && $currentUserId === SUPERUSER_ID) {
        $offset = intval($_GET['offset'] ?? 0);
        $limit = 15;
        $slice = array_slice($logs, $offset, $limit);
        $output = [];
        foreach ($slice as $l) {
            $output[] = [
                'date' => date('Y-m-d H:i:s', $l['timestamp']),
                'user_id' => $l['user_id'],
                'action' => $l['action'],
                'detail' => $l['detail']
            ];
        }
        echo json_encode($output);
        exit;
    }
    
    if ($apiAction === 'suggest_item') {
        $q = strtolower($_GET['q'] ?? '');
        $suggestions = [];
        if ($q !== '') {
            foreach ($index['registry'] as $id => $meta) {
                if (strpos(strtolower($meta['label']), $q) !== false) {
                    $suggestions[] = ['id' => $id, 'label' => $meta['label']];
                }
                if (count($suggestions) >= 8) break;
            }
        }
        echo json_encode($suggestions);
        exit;
    }
    
    if ($apiAction === 'suggest_prop') {
        $q = strtolower($_GET['q'] ?? '');
        $suggestions = [];
        if ($q !== '') {
            foreach ($index['properties'] as $pid => $pData) {
                if (empty($pData['deleted']) && strpos(strtolower($pData['label']), $q) !== false) {
                    $suggestions[] = ['id' => $pid, 'label' => $pData['label']];
                }
                if (count($suggestions) >= 8) break;
            }
        }
        echo json_encode($suggestions);
        exit;
    }

    if ($apiAction === 'suggest_wikidata') {
        $q = trim($_GET['q'] ?? '');
        $suggestions = [];
        if ($q !== '') {
            $url = "https://www.wikidata.org/w/api.php?action=wbsearchentities&search=" . urlencode($q) . "&language=en&format=json&limit=8";
            $opts = [
                'http' => [
                    'method' => 'GET',
                    'header' => "User-Agent: MicroWikidataMatrixEngine/1.1 (Dynamic Engine Proxy)\r\n"
                ]
            ];
            $context = stream_context_create($opts);
            $resp = @file_get_contents($url, false, $context);
            if ($resp) {
                $resData = json_decode($resp, true);
                if (!empty($resData['search'])) {
                    foreach ($resData['search'] as $sItem) {
                        $suggestions[] = [
                            'id' => $sItem['id'],
                            'label' => $sItem['label'] ?? $sItem['id'],
                            'desc' => $sItem['description'] ?? ''
                        ];
                    }
                }
            }
        }
        echo json_encode($suggestions);
        exit;
    }
}

// POST REQUEST TRANSACTION MANAGEMENT
$msg = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isBanned) {
    $route = $_GET['route'] ?? '';

    if ($route === 'add_item') {
        $label = trim($_POST['label'] ?? '');
        if ($label !== '') {
            $lowerLabel = strtolower($label);
            if (isset($index['labels'][$lowerLabel])) {
                $msg = "Error: An item matching this entity identity already exists.";
            } else {
                $newId = $index['next_item_id']++;
                $index['labels'][$lowerLabel] = $newId;
                $index['registry'][$newId] = ['label' => $label, 'timestamp' => time()];
                db_save_item($newId, ['label' => $label, 'statements' => []]);
                db_save_json('index.json', $index);
                log_activity($currentUserId, 'add_item', "Created item Q$newId: $label");
                header("Location: ?id=" . $newId);
                exit;
            }
        }
    }

    if ($route === 'admin_delete_item' && $currentUserId === SUPERUSER_ID) {
        $itemId = $_POST['item_id'] ?? '';
        $item = db_get_item($itemId);
        if ($item) {
            $lowerLabel = strtolower($item['label']);
            unset($index['labels'][$lowerLabel]);
            unset($index['registry'][$itemId]);
            
            $chunkFilename = db_get_chunk_filename($itemId);
            $chunk = db_load_json($chunkFilename);
            unset($chunk[$itemId]);
            db_save_json($chunkFilename, $chunk);
            
            db_save_json('index.json', $index);
            log_activity($currentUserId, 'delete_item', "Admin purged node identity Q$itemId: {$item['label']}");
            header("Location: ?");
            exit;
        }
    }

    if ($route === 'edit_item_label') {
        $itemId = $_POST['item_id'] ?? '';
        $newLabel = trim($_POST['new_label'] ?? '');
        $item = db_get_item($itemId);
        if ($item && $newLabel !== '') {
            $oldLower = strtolower($item['label']);
            $newLower = strtolower($newLabel);
            if ($oldLower !== $newLower && isset($index['labels'][$newLower])) {
                $msg = "Error: Label update collides with an existing item matrix entry.";
            } else {
                unset($index['labels'][$oldLower]);
                $index['labels'][$newLower] = $itemId;
                $index['registry'][$itemId]['label'] = $newLabel;
                $item['label'] = $newLabel;
                db_save_item($itemId, $item);
                db_save_json('index.json', $index);
                log_activity($currentUserId, 'edit_label', "Updated item Q$itemId label to $newLabel");
                header("Location: ?id=" . $itemId);
                exit;
            }
        }
    }

    if ($route === 'add_property') {
        $pLabel = trim($_POST['property_label'] ?? '');
        if ($pLabel !== '') {
            $pId = 'P' . (count($index['properties']) + 1);
            $index['properties'][$pId] = ['label' => $pLabel, 'deleted' => false];
            db_save_json('index.json', $index);
            log_activity($currentUserId, 'add_property', "Created property $pId: $pLabel");
            header("Location: ?route=properties");
            exit;
        }
    }

    if ($route === 'delete_property' && $currentUserId === SUPERUSER_ID) {
        $pId = $_POST['property_id'] ?? '';
        if (isset($index['properties'][$pId])) {
            $index['properties'][$pId]['deleted'] = true;
            db_save_json('index.json', $index);
            log_activity($currentUserId, 'delete_property', "Deleted property $pId");
        }
    }

    if ($route === 'add_statement') {
        $itemId = $_POST['item_id'] ?? '';
        $pId = $_POST['property_id'] ?? '';
        $mode = $_POST['value_mode'] ?? 'text';
        $val = trim($_POST['value'] ?? '');
        
        if ($mode === 'link_internal' && !empty($_POST['internal_item_id'])) {
            $val = ':' . $_POST['internal_item_id'];
        } elseif ($mode === 'link_wikidata' && !empty($_POST['wikidata_id'])) {
            $wdId = trim($_POST['wikidata_id']);
            $wdLabel = trim($_POST['wikidata_label'] ?? '');
            $val = ';' . $wdId . ($wdLabel !== '' ? '|' . $wdLabel : '');
        }
        
        $item = db_get_item($itemId);
        if ($item && isset($index['properties'][$pId]) && !$index['properties'][$pId]['deleted']) {
            $item['statements'][] = ['p_id' => $pId, 'value' => $val];
            db_save_item($itemId, $item);
            
            if (strpos($val, ':') === 0) {
                $targetId = substr($val, 1);
                $index['backlinks'][$targetId][] = $itemId;
                $index['backlinks'][$targetId] = array_unique($index['backlinks'][$targetId]);
                db_save_json('index.json', $index);
            }

            $propStringName = $index['properties'][$pId]['label'] ?? $pId;
            $resolvedValueDetails = $val;
            if (strpos($val, ':') === 0) {
                $resolvingTargetId = substr($val, 1);
                $resolvingItemObj = db_get_item($resolvingTargetId);
                $resolvedValueDetails = ($resolvingItemObj['label'] ?? "Unknown Entity") . " (Q" . $resolvingTargetId . ")";
            } elseif (strpos($val, ';') === 0) {
                $parts = explode('|', substr($val, 1));
                $resolvedValueDetails = ($parts[1] ?? $parts[0]) . " (" . $parts[0] . ") [Wikidata]";
            }
            log_activity($currentUserId, 'add_statement', "Added claim to Q$itemId: $propStringName ($pId) -> $resolvedValueDetails");
            
            header("Location: ?id=" . $itemId);
            exit;
        }
    }

    if ($route === 'rearrange_statement') {
        $itemId = $_POST['item_id'] ?? '';
        $indexPos = intval($_POST['index'] ?? -1);
        $direction = $_POST['dir'] ?? '';
        $item = db_get_item($itemId);
        if ($item && $indexPos >= 0 && isset($item['statements'][$indexPos])) {
            $targetPos = ($direction === 'up') ? $indexPos - 1 : $indexPos + 1;
            if ($targetPos >= 0 && $targetPos < count($item['statements'])) {
                $tmp = $item['statements'][$indexPos];
                $item['statements'][$indexPos] = $item['statements'][$targetPos];
                $item['statements'][$targetPos] = $tmp;
                db_save_item($itemId, $item);
            }
            header("Location: ?id=" . $itemId . "&show_matrix=1");
            exit;
        }
    }

    if ($route === 'delete_statement') {
        $itemId = $_POST['item_id'] ?? '';
        $indexPos = intval($_POST['index'] ?? -1);
        $item = db_get_item($itemId);
        if ($item && $indexPos >= 0 && isset($item['statements'][$indexPos])) {
            $deletedStmt = $item['statements'][$indexPos];
            array_splice($item['statements'], $indexPos, 1);
            db_save_item($itemId, $item);
            log_activity($currentUserId, 'delete_statement', "Removed statement entry position $indexPos from Q$itemId");
            header("Location: ?id=" . $itemId . "&show_matrix=1");
            exit;
        }
    }

    if ($route === 'manage_lists') {
        $sub = $_POST['sub_action'] ?? '';
        if ($sub === 'create') {
            $listName = trim($_POST['list_name'] ?? '');
            if ($listName !== '') {
                $users[$currentUserId]['lists'][$listName] = ['items' => [], 'comments' => []];
                db_save_json('users.json', $users);
            }
        } elseif ($sub === 'delete') {
            $listName = $_POST['list_name'] ?? '';
            unset($users[$currentUserId]['lists'][$listName]);
            db_save_json('users.json', $users);
        } elseif ($sub === 'rename') {
            $oldName = $_POST['old_name'] ?? '';
            $newName = trim($_POST['new_name'] ?? '');
            if ($newName !== '' && isset($users[$currentUserId]['lists'][$oldName])) {
                $users[$currentUserId]['lists'][$newName] = $users[$currentUserId]['lists'][$oldName];
                unset($users[$currentUserId]['lists'][$oldName]);
                db_save_json('users.json', $users);
            }
        } elseif ($sub === 'add_item_to_list') {
            $listName = $_POST['list_name'] ?? '';
            $itId = $_POST['internal_item_id'] ?? '';
            if ($itId && isset($users[$currentUserId]['lists'][$listName])) {
                if (!in_array($itId, $users[$currentUserId]['lists'][$listName]['items'])) {
                    $users[$currentUserId]['lists'][$listName]['items'][] = $itId;
                    $index['lists'][$itId][] = $currentUserId . '||' . $listName;
                    $index['lists'][$itId] = array_unique($index['lists'][$itId]);
                    db_save_json('index.json', $index);
                }
                $users[$currentUserId]['lists'][$listName]['comments'][$itId] = trim($_POST['comment'] ?? '');
                db_save_json('users.json', $users);
            }
            header("Location: ?route=profile");
            exit;
        } elseif ($sub === 'update_comment') {
            $listName = $_POST['list_name'] ?? '';
            $itId = $_POST['item_id'] ?? '';
            if (isset($users[$currentUserId]['lists'][$listName])) {
                $users[$currentUserId]['lists'][$listName]['comments'][$itId] = trim($_POST['comment'] ?? '');
                db_save_json('users.json', $users);
            }
            header("Location: ?route=profile");
            exit;
        } elseif ($sub === 'remove_item') {
            $listName = $_POST['list_name'] ?? '';
            $itId = $_POST['item_id'] ?? '';
            if (isset($users[$currentUserId]['lists'][$listName])) {
                $idx = array_search($itId, $users[$currentUserId]['lists'][$listName]['items']);
                if ($idx !== false) {
                    array_splice($users[$currentUserId]['lists'][$listName]['items'], $idx, 1);
                    unset($users[$currentUserId]['lists'][$listName]['comments'][$itId]);
                    db_save_json('users.json', $users);
                }
            }
        } elseif ($sub === 'rearrange_item') {
            $listName = $_POST['list_name'] ?? '';
            $itId = $_POST['item_id'] ?? '';
            $dir = $_POST['dir'] ?? '';
            if (isset($users[$currentUserId]['lists'][$listName])) {
                $arr = &$users[$currentUserId]['lists'][$listName]['items'];
                $idx = array_search($itId, $arr);
                if ($idx !== false) {
                    $targ = ($dir === 'up') ? $idx - 1 : $idx + 1;
                    if ($targ >= 0 && $targ < count($arr)) {
                        $tmp = $arr[$idx]; $arr[$idx] = $arr[$targ]; $arr[$targ] = $tmp;
                        db_save_json('users.json', $users);
                    }
                }
            }
        }
        header("Location: ?route=profile");
        exit;
    }

    if ($route === 'forum_create_thread') {
        $title = trim($_POST['title'] ?? '');
        $body = trim($_POST['body'] ?? '');
        if ($title !== '' && $body !== '') {
            $tId = 't_' . bin2hex(random_bytes(6));
            $forum[$tId] = [
                'id' => $tId, 'title' => $title, 'user_id' => $currentUserId, 'timestamp' => time(),
                'body' => $body, 'replies' => []
            ];
            db_save_json('forum_threads.json', $forum);
            log_activity($currentUserId, 'forum_thread', "Created discussion thread: $title");
            header("Location: ?route=forum");
            exit;
        }
    }

    if ($route === 'forum_add_reply') {
        $tId = $_POST['thread_id'] ?? '';
        $body = trim($_POST['body'] ?? '');
        if (isset($forum[$tId]) && $body !== '') {
            $rId = 'r_' . bin2hex(random_bytes(6));
            $forum[$tId]['replies'][] = [
                'id' => $rId, 'user_id' => $currentUserId, 'timestamp' => time(), 'body' => $body
            ];
            db_save_json('forum_threads.json', $forum);
            log_activity($currentUserId, 'forum_reply', "Replied to thread reference: $tId");
            header("Location: ?route=forum_view&thread_id=" . $tId);
            exit;
        }
    }

    if ($route === 'forum_delete_thread' && $currentUserId === SUPERUSER_ID) {
        $tId = $_POST['thread_id'] ?? '';
        if (isset($forum[$tId])) {
            unset($forum[$tId]);
            db_save_json('forum_threads.json', $forum);
            log_activity($currentUserId, 'forum_delete_thread', "Admin extracted thread block $tId");
            header("Location: ?route=forum");
            exit;
        }
    }

    if ($route === 'forum_delete_main_post' && $currentUserId === SUPERUSER_ID) {
        $tId = $_POST['thread_id'] ?? '';
        if (isset($forum[$tId])) {
            $forum[$tId]['body'] = '[This foundational post content has been deleted by an administrator]';
            db_save_json('forum_threads.json', $forum);
            log_activity($currentUserId, 'forum_clean_post', "Admin cleared root body text for thread node $tId");
            header("Location: ?route=forum_view&thread_id=" . $tId);
            exit;
        }
    }

    if ($route === 'forum_delete_reply' && $currentUserId === SUPERUSER_ID) {
        $tId = $_POST['thread_id'] ?? '';
        $rId = $_POST['reply_id'] ?? '';
        if (isset($forum[$tId])) {
            foreach ($forum[$tId]['replies'] as $idx => $rep) {
                if ($rep['id'] === $rId) {
                    array_splice($forum[$tId]['replies'], $idx, 1);
                    log_activity($currentUserId, 'forum_clean_reply', "Admin purged reply index segment $rId inside thread $tId");
                    break;
                }
            }
            db_save_json('forum_threads.json', $forum);
            header("Location: ?route=forum_view&thread_id=" . $tId);
            exit;
        }
    }

    if ($route === 'send_msg') {
        $to = $_POST['to_user'] ?? '';
        $text = trim($_POST['message'] ?? '');
        if (isset($users[$to]) && $text !== '') {
            $users[$to]['messages'][] = ['from' => $currentUserId, 'timestamp' => time(), 'text' => $text];
            db_save_json('users.json', $users);
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }
    }

    if ($route === 'admin_action' && $currentUserId === SUPERUSER_ID) {
        $target = $_POST['target_user'] ?? '';
        $type = $_POST['type'] ?? '';
        if (isset($users[$target])) {
            if ($type === 'ban_perm') { $users[$target]['banned'] = true; $users[$target]['ban_until'] = -1; }
            elseif ($type === 'ban_temp') { $users[$target]['banned'] = true; $users[$target]['ban_until'] = time() + (intval($_POST['duration'] ?? 1) * 60); }
            elseif ($type === 'unban') { $users[$target]['banned'] = false; $users[$target]['ban_until'] = 0; }
            db_save_json('users.json', $users);
            header("Location: ?route=admin");
            exit;
        }
    }
}

function render_value($val) {
    global $index;
    if (strpos($val, ':') === 0) {
        $targetId = substr($val, 1);
        $lbl = $index['registry'][$targetId]['label'] ?? "Unknown Item Node";
        return "<a href='?id=" . htmlspecialchars($targetId) . "'>" . htmlspecialchars($lbl) . " (Q" . htmlspecialchars($targetId) . ")</a>";
    }
    if (strpos($val, ';') === 0) {
        $parts = explode('|', substr($val, 1));
        $wdId = $parts[0];
        $wdLabel = !empty($parts[1]) ? $parts[1] : $wdId;
        return "<a href='https://www.wikidata.org/wiki/" . htmlspecialchars($wdId) . "' target='_blank'><strong>" . htmlspecialchars($wdLabel) . "</strong> (" . htmlspecialchars($wdId) . ") [Wikidata] ↗</a>";
    }
    if (filter_var($val, FILTER_VALIDATE_URL)) {
        return "<a href='" . htmlspecialchars($val) . "' target='_blank'>" . htmlspecialchars($val) . " ↗</a>";
    }
    return htmlspecialchars($val);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <link rel="icon" href="https://pbs.twimg.com/profile_images/1716831335724326912/8ujZJHcJ_400x400.jpg" type="image/x-icon" />
    <title>Altilunium Panoply v26.6.22</title>
    <style>
        * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: #f6f8fa; color: #24292e; margin: 0; padding: 4px; font-size: 11px; line-height: 1.3; }
        a { color: #0366d6; text-decoration: none; }
        a:hover { text-decoration: underline; }
        header { background: #24292e; color: #fff; padding: 4px 8px; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; }
        header h1 { margin: 0; font-size: 13px; font-weight: bold; }
        header nav a { color: #fff; margin-left: 8px; }
        .container { max-width: 100%; margin: 0 auto; background: #fff; border: 1px solid #e1e4e8; padding: 6px; position: relative; }
        .alert { background: #ffeef0; border: 1px solid #f97583; color: #86181d; padding: 4px; margin-bottom: 4px; font-weight: bold; }
        .grid { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px; }
        .col { flex: 1; min-width: 280px; border: 1px solid #e1e4e8; padding: 6px; background: #fafbfc; position: relative; }
        h2 { font-size: 12px; margin: 0 0 4px 0; padding-bottom: 2px; border-bottom: 1px solid #e1e4e8; color: #586069; }
        input[type="text"], textarea, select { width: 100%; font-size: 11px; padding: 2px 4px; margin-bottom: 4px; border: 1px solid #d1d5da; border-radius: 2px; }
        button, input[type="submit"] { background: #24292e; color: #fff; border: 0; padding: 2px 6px; font-size: 11px; cursor: pointer; border-radius: 2px; }
        button:hover, input[type="submit"]:hover { background: #444; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        th, td { border: 1px solid #e1e4e8; padding: 3px 4px; text-align: left; vertical-align: top; }
        th { background: #f1f2f3; font-weight: bold; width: 30%; }
        .action-btn { display: inline; margin: 0; padding: 0; background: none; color: #0366d6; border: none; font-size: 11px; cursor: pointer; }
        .action-btn:hover { text-decoration: underline; }
        .footer-section { margin-top: 8px; border-top: 2px solid #24292e; padding-top: 4px; background: #f9f9f9; }
        .badge { background: #e1e4e8; color: #24292e; padding: 1px 3px; border-radius: 2px; font-size: 10px; }
        .ac-dropdown { position: absolute; background: white; border: 1px solid #aaa; width: 100%; max-height: 160px; overflow-y: auto; z-index: 9999; display: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .ac-item { padding: 3px 6px; cursor: pointer; border-bottom: 1px solid #eee; }
        .ac-item:hover { background: #0366d6; color: white; }
        .forum-card { background: #fff; border: 1px solid #e1e4e8; padding: 6px; margin-bottom: 4px; border-radius: 2px; }
        .chat-container { border: 1px solid #ccc; padding: 4px; background: #fff; max-height: 180px; overflow-y: auto; margin-bottom: 4px; }
        .chat-bubble { padding: 3px; border-radius: 3px; margin-bottom: 3px; font-size: 10px; }
        .chat-inbound { background: #e2f0fd; border-left: 3px solid #0366d6; }
        .chat-outbound { background: #f0f4f8; border-left: 3px solid #6a737d; text-align: right; }
        .matrix-col { display: none; }
    </style>
</head>
<body>

<header>
    <h1><a href="?" style="color:#fff;">⚙️ Altilunium Panoply v26.6.22</a> <span class="badge"><?= ($currentUserId === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($currentUserId) ?></span></h1>
    <nav>
        <a href="?">Items Node</a>
        <a href="?route=properties">Properties</a>
        <a href="?route=forum">Forum Board</a>
        <a href="?route=profile">My Profile Space</a>
        <?php if ($currentUserId === SUPERUSER_ID): ?><a href="?route=admin" style="color:#ff3f3f; font-weight:bold;">Admin Control</a><?php endif; ?>
    </nav>
</header>

<div class="container">
    <?php if ($isBanned): ?><div class="alert">Your structural identity signature has been flagged and suspended. Global ledger writing functions are disabled.</div><?php endif; ?>
    <?php if ($msg): ?><div class="alert"><?= $msg ?></div><?php endif; ?>

    <?php
    $viewRoute = $_GET['route'] ?? '';
    $viewId = $_GET['id'] ?? '';

    // ROUTE: INDEX / HOMEPAGE STREAM WITH PAGINATION
    if ($viewRoute === '' && $viewId === ''): ?>
        <div class="grid">
            <div class="col">
                <h2>Instantiate New Graph Node Entity</h2>
                <form action="?route=add_item" method="POST">
                    <label>Unique Matrix Label Signature:</label>
                    <input type="text" name="label" required placeholder="Enter exact original casing name...">
                    <input type="submit" value="Publish Entity Node" <?= $isBanned ? 'disabled' : '' ?>>
                </form>
            </div>
            <div class="col">
                <h2>Real-time Activity Stream Index</h2>
                <div id="items-stream-container">
                    <table id="items-stream-table">
                        <thead><tr><th>Entity ID</th><th>Original Identifier Label Reference</th></tr></thead>
                        <tbody id="items-stream-body"></tbody>
                    </table>
                </div>
                <div style="text-align: center; margin-top: 4px;">
                    <button id="load-more-btn" onclick="fetchNextStreamBatch()">Download Next Segment Layer ↓</button>
                </div>
            </div>
        </div>

    <?php
    // ROUTE: PROPERTY SYSTEM MANAGEMENT
    elseif ($viewRoute === 'properties'): ?>
        <div class="grid">
            <div class="col">
                <h2>Register Semantic Structural Property</h2>
                <form action="?route=add_property" method="POST">
                    <input type="text" name="property_label" required placeholder="e.g., localized manufacturing origin">
                    <input type="submit" value="Inject System Property" <?= $isBanned ? 'disabled' : '' ?>>
                </form>
            </div>
            <div class="col">
                <h2>Active Semantic Declarations Matrix</h2>
                <table>
                    <tr><th>PID Pointers</th><th>Property Label Specification</th><th>Management Policy</th></tr>
                    <?php foreach ($index['properties'] as $pid => $pData): if (!empty($pData['deleted'])) continue; ?>
                        <tr>
                            <td><?= $pid ?></td>
                            <td><?= htmlspecialchars($pData['label']) ?></td>
                            <td>
                                <?php if ($currentUserId === SUPERUSER_ID): ?>
                                    <form action="?route=delete_property" method="POST" style="display:inline;">
                                        <input type="hidden" name="property_id" value="<?= $pid ?>">
                                        <input type="submit" class="action-btn" value="[Purge Structural Node]" onclick="return confirm('Purge property schema?');">
                                    </form>
                                <?php else: echo "<span style='color:#aaa;'>Immutable</span>"; endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </div>

    <?php
    // ROUTE: KNOWLEDGE NODE EDITING AND CLAIM INTERACTION
    elseif ($viewId !== ''):
        $item = db_get_item($viewId);
        if (!$item): echo "<div class='alert'>Knowledge block entity Q$viewId does not exist inside current file frames.</div>";
        else: ?>
            <div style="background:#f1f2f3; padding:4px; margin-bottom:4px; border:1px solid #d1d5da; display:flex; justify-content:space-between; align-items:center;">
                <form action="?route=edit_item_label" method="POST" style="display:flex; align-items:center; gap:4px; margin:0; flex:1; flex-wrap:wrap">
                    <input type="hidden" name="item_id" value="<?= $viewId ?>">
                    <strong>Entity Q<?= $viewId ?> Node Identity: </strong>
                    <input type="text" name="new_label" value="<?= htmlspecialchars($item['label']) ?>" style="margin:0; flex:1; font-weight:bold;field-sizing:content;">
                    <input type="submit" value="Apply Identity Correction" <?= $isBanned ? 'disabled' : '' ?>>
                    <button type="button" style="margin-left:4px; background:#6a737d;" onclick="toggleMatrixColumns()">modify order-action matrix</button>
                </form>
                <?php if ($currentUserId === SUPERUSER_ID): ?>
                    <form action="?route=admin_delete_item" method="POST" style="margin-left:8px;" onsubmit="return confirm('Completely eradicate this item entity node from the global ledger?');">
                        <input type="hidden" name="item_id" value="<?= $viewId ?>">
                        <button type="submit" style="background:#d73a49; color:#fff;">⚠️ Delete Node</button>
                    </form>
                <?php endif; ?>
            </div>

            <table id="statements-matrix-table">
                <thead>
                    <tr><th>Declared Semantic Link Property</th><th>Parsed Value Definition Claims</th><th class="matrix-col">Order Matrix</th><th class="matrix-col">Action Matrix</th></tr>
                </thead>
                <tbody>
                <?php if (empty($item['statements'])): ?>
                    <tr><td colspan="4" style="color:#aaa; text-align:center;">No relational matrix links map out from this entity block.</td></tr>
                <?php else: foreach ($item['statements'] as $idx => $stmt):
                    $propLabel = $index['properties'][$stmt['p_id']]['label'] ?? $stmt['p_id'];
                    if (!empty($index['properties'][$stmt['p_id']]['deleted'])) continue;
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($propLabel) ?></strong> <span class="badge"><?= htmlspecialchars($stmt['p_id']) ?></span></td>
                        <td><?= render_value($stmt['value']) ?></td>
                        <td class="matrix-col">
                            <form action="?route=rearrange_statement" method="POST" style="display:inline;">
                                <input type="hidden" name="item_id" value="<?= $viewId ?>"><input type="hidden" name="index" value="<?= $idx ?>">
                                <button type="submit" name="dir" value="up" <?= $isBanned ? 'disabled' : '' ?>>▲</button>
                                <button type="submit" name="dir" value="down" <?= $isBanned ? 'disabled' : '' ?>>▼</button>
                            </form>
                        </td>
                        <td class="matrix-col">
                            <form action="?route=delete_statement" method="POST" style="display:inline;">
                                <input type="hidden" name="item_id" value="<?= $viewId ?>"><input type="hidden" name="index" value="<?= $idx ?>">
                                <input type="submit" class="action-btn" value="[Delete Assignment]" <?= $isBanned ? 'disabled' : '' ?> onclick="return confirm('Log statement extraction?');">
                            </form>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>

            <div class="col" style="margin-top:4px;">
                <h2>Append Structural Relational Statement</h2>
                <form action="?route=add_statement" method="POST" style="display:flex; flex-direction:column; gap:2px;">
                    <input type="hidden" name="item_id" value="<?= $viewId ?>">
                    
                    <div style="position:relative;">
                        <label>Target Schema Property Element (Type to Search Autocomplete Suggestions):</label>
                        <input type="text" class="autocomplete-trigger" data-type="prop" placeholder="Search system configuration properties map..." required autocomplete="off">
                        <input type="hidden" name="property_id" class="autocomplete-target">
                        <div class="ac-dropdown"></div>
                    </div>

                    <div style="margin:4px 0; border:1px dashed #ccc; padding:4px; background:#fff;">
                        <label><input type="radio" name="value_mode" value="text" checked onchange="toggleValueInputs(this)"> Free String / Remote Web URL Data Payload</label>
                        <label style="margin-left:8px;"><input type="radio" name="value_mode" value="link_internal" onchange="toggleValueInputs(this)"> Link Node To Internal Database Entity</label>
                        <label style="margin-left:8px;"><input type="radio" name="value_mode" value="link_wikidata" onchange="toggleValueInputs(this)"> Link Node To Wikidata Items</label>
                    </div>

                    <div id="wrapper-value-text">
                        <input type="text" name="value" placeholder="Enter alphanumeric text statement or external hyperlink structure...">
                    </div>

                    <div id="wrapper-value-link" style="display:none; position:relative;">
                        <input type="text" class="autocomplete-trigger" data-type="item" placeholder="Search item nodes database by name mapping..." autocomplete="off">
                        <input type="hidden" name="internal_item_id" class="autocomplete-target">
                        <div class="ac-dropdown"></div>
                    </div>

                    <div id="wrapper-value-wikidata" style="display:none; position:relative;">
                        <input type="text" class="autocomplete-trigger" data-type="wikidata" placeholder="Type to search live remote Wikidata entities (e.g., Douglas Adams)..." autocomplete="off">
                        <input type="hidden" name="wikidata_id" class="autocomplete-target">
                        <input type="hidden" name="wikidata_label" class="autocomplete-extra-target">
                        <div class="ac-dropdown"></div>
                    </div>

                    <input type="submit" value="Commit Statement Framework" style="margin-top:4px;" <?= $isBanned ? 'disabled' : '' ?>>
                </form>
            </div>

            <div class="footer-section">
                <h2>Inverse Node Entry Trace Mapping (Backlinks Index)</h2>
                <?php
                $blinks = $index['backlinks'][$viewId] ?? [];
                if (empty($blinks)): echo "<p style='color:#666; margin:2px;'>No active database rows refer back to this entity index.</p>";
                else: foreach ($blinks as $blId) {
                    $bItem = db_get_item($blId);
                    if ($bItem) echo "<span class='badge' style='margin-right:4px;'><a href='?id=$blId'>" . htmlspecialchars($bItem['label']) . " (Q$blId)</a></span>";
                } endif;
                ?>
            </div>

            <div class="footer-section">
                <h2>Active Public Collections Map Containing This Entity Block</h2>
                <?php
                $listRefs = $index['lists'][$viewId] ?? [];
                $foundList = false;
                foreach ($listRefs as $ref) {
                    $parts = explode('||', $ref);
                    if (count($parts) === 2) {
                        $uId = $parts[0]; $lName = $parts[1];
                        if (isset($users[$uId]['lists'][$lName]) && in_array($viewId, $users[$uId]['lists'][$lName]['items'])) {
                            $displayRefUser = ($uId === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($uId);

                            $encrypted_uuid = openssl_encrypt($uId, $cipher, $app_key, 0, $iv);

                            echo "<p style='margin:2px;'>📁 Public Compilation Title: <a href='?route=view_list&user_id=" . urlencode($encrypted_uuid) . "&list_name=" . urlencode($lName) . "'><strong>" . htmlspecialchars($lName) . "</strong></a> curated by node user tracking string <em>" . $encrypted_uuid . "</em></p>";
                            $foundList = true;
                        }
                    }
                }
                if (!$foundList) echo "<p style='color:#666; margin:2px;'>This element sits inside no compiled personal index frames.</p>";
                ?>
            </div>
        <?php endif; ?>

    <?php
    // ROUTE: PUBLIC LIST COMPILATION VIEWER
    elseif ($viewRoute === 'view_list'):
        $tgtUser = openssl_decrypt($_GET['user_id'], $cipher, $app_key, 0, $iv) ?? '';
        $tgtListName = $_GET['list_name'] ?? '';
        $targetList = $users[$tgtUser]['lists'][$tgtListName] ?? null;
        if (!$targetList): echo "<div class='alert'>The specified user data compilation list was modified or pulled from access availability.</div>";
        else: 
            $displayTgtUser = ($tgtUser === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($_GET['user_id']);
        ?>
            <h2>Curated Public Graph Set Index: <?= htmlspecialchars($tgtListName) ?> [Published by Node: <?= $displayTgtUser ?>]</h2>
            <table>
                <tr><th>Assigned Node Identity</th><th>Curator Annotation Log Explanations</th></tr>
                <?php if (empty($targetList['items'])): ?>
                    <tr><td colspan="2" style="color:#aaa; text-align:center;">No tracking matrix tokens associated inside this bucket container.</td></tr>
                <?php else: foreach ($targetList['items'] as $itId): $itObj = db_get_item($itId); ?>
                    <tr>
                        <td><a href="?id=<?= $itId ?>"><strong><?= htmlspecialchars($itObj['label'] ?? "Q$itId") ?></strong> (Q<?= $itId ?>)</a></td>
                        <td><?= htmlspecialchars($targetList['comments'][$itId] ?? 'No metadata descriptors applied.') ?></td>
                    </tr>
                <?php endforeach; endif; ?>
            </table>
        <?php endif; ?>

    <?php
    // ROUTE: THREADED COMMUNITY DISCUSSION BOARD
    elseif ($viewRoute === 'forum'): ?>
        <h2>Structured System Discussion Framework Channels</h2>
        <div class="col" style="margin-bottom:4px;">
            <h2>Open New Systematic Resolution Thread</h2>
            <form action="?route=forum_create_thread" method="POST">
                <input type="text" name="title" required placeholder="Subject Thread Title Header Detail...">
                <textarea name="body" rows="2" required placeholder="Describe the structural data mismatch or design proposal scope..."></textarea>
                <input type="submit" value="Initialize Open Thread" <?= $isBanned ? 'disabled' : '' ?>>
            </form>
        </div>

        <h2>Open Matrix Discussion Logs</h2>
        <?php if (empty($forum)): echo "<p style='color:#aaa;'>No system coordination channels initialized.</p>";
        else: foreach (array_reverse($forum) as $tId => $thread): 
            $displayThreadAuthor = ($thread['user_id'] === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars(openssl_encrypt($thread['user_id'], $cipher, $app_key, 0, $iv));
        ?>
            <div class="forum-card">
                <div style="display:flex; justify-content:between; align-items:center;">
                    <h3>💬 <a href="?route=forum_view&thread_id=<?= $tId ?>"><strong><?= htmlspecialchars($thread['title']) ?></strong></a></h3>
                    <div style="margin-left:auto;">
                        <span class="badge">Replies Count: <?= count($thread['replies']) ?></span>
                        <?php if ($currentUserId === SUPERUSER_ID): ?>
                            <form action="?route=forum_delete_thread" method="POST" style="display:inline;">
                                <input type="hidden" name="thread_id" value="<?= $tId ?>">
                                <input type="submit" value="[Purge Thread Context]" class="action-btn" style="color:red;" onclick="return confirm('Destroy entire conversation structure and responses?');">
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
                <small style="color:#666;">Generated by user ID tracker: <?= $displayThreadAuthor ?> on timeline allocation: <?= date('Y-m-d H:i', $thread['timestamp']) ?></small>
            </div>
        <?php endforeach; endif; ?>

    <?php
    // ROUTE: MODULAR THREAD COMMENT MATRIX VIEW
    elseif ($viewRoute === 'forum_view'):
        $tId = $_GET['thread_id'] ?? '';
        $thread = $forum[$tId] ?? null;
        if (!$thread): echo "<div class='alert'>Discussion system coordinate reference is missing.</div>";
        else: 
            $displayMainAuthor = ($thread['user_id'] === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars(openssl_encrypt($thread['user_id'], $cipher, $app_key, 0, $iv));
        ?>
            <h2>Thread Scope Channel: <?= htmlspecialchars($thread['title']) ?></h2>
            <div style="background:#fafbfc; border:1px solid #d1d5da; padding:6px; margin-bottom:6px; position:relative;">
                <strong>User Root Tracker: <?= $displayMainAuthor ?></strong>
                <small style="color:#777; margin-left:8px;"><?= date('Y-m-d H:i:s', $thread['timestamp']) ?></small>
                
                <?php if ($currentUserId === SUPERUSER_ID): ?>
                    <form action="?route=forum_delete_main_post" method="POST" style="position:absolute; right:4px; top:4px; display:inline;">
                        <input type="hidden" name="thread_id" value="<?= $tId ?>">
                        <input type="submit" value="[Erase Main Post Content]" class="action-btn" style="color:#d73a49; font-weight:bold;" onclick="return confirm('Replace post content with deletion placeholder? Thread replies remain.');">
                    </form>
                <?php endif; ?>
                
                <p style="white-space:pre-wrap; margin:4px 0; font-size:12px;"><?= htmlspecialchars($thread['body']) ?></p>
            </div>

            <h3 style="margin-left:8px;">Response Block Processing Queue</h3>
            <div style="padding-left:12px; border-left:2px solid #ddd; margin-bottom:6px;">
                <?php if (empty($thread['replies'])): echo "<p style='color:#aaa;'>No relational comments appended onto this record thread.</p>";
                else: foreach ($thread['replies'] as $rep): 
                    $displayReplyAuthor = ($rep['user_id'] === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars(openssl_encrypt($rep['user_id'], $cipher, $app_key, 0, $iv));
                ?>
                    <div style="background:#fff; border:1px solid #e1e4e8; padding:4px; margin-bottom:4px; position:relative;">
                        <strong><?= $displayReplyAuthor ?></strong> 
                        <small style="color:#888;"><?= date('Y-m-d H:i', $rep['timestamp']) ?></small>
                        <?php if ($currentUserId === SUPERUSER_ID): ?>
                            <form action="?route=forum_delete_reply" method="POST" style="position:absolute; right:4px; top:4px; display:inline;">
                                <input type="hidden" name="thread_id" value="<?= $tId ?>"><input type="hidden" name="reply_id" value="<?= $rep['id'] ?>">
                                <input type="submit" value="[Expel Reply]" class="action-btn" style="color:red;" onclick="return confirm('Purge individual text cell comment block?');">
                            </form>
                        <?php endif; ?>
                        <p style="margin:2px 0 0 4px; white-space:pre-wrap;"><?= htmlspecialchars($rep['body']) ?></p>
                    </div>
                <?php endforeach; endif; ?>
            </div>

            <form action="?route=forum_add_reply" method="POST" style="margin-top:4px;">
                <input type="hidden" name="thread_id" value="<?= $tId ?>">
                <textarea name="body" rows="2" required placeholder="Append analytical explanation response logic..." <?= $isBanned ? 'disabled' : '' ?>></textarea>
                <input type="submit" value="Dispatch Statement Reply" <?= $isBanned ? 'disabled' : '' ?>>
            </form>
        <?php endif; ?>

    <?php
    // ROUTE: PROFILE CONFIGURATION SPACE
    elseif ($viewRoute === 'profile'): ?>
        <h2>Custom User Entity Parameters Profile: <?= ($currentUserId === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($currentUserId) ?></h2>
        <div class="grid">
            <div class="col">
                <h2>Direct Secure Inbox Terminal</h2>
                <div class="chat-container">
                    <?php
                    $myMsgs = $users[$currentUserId]['messages'] ?? [];
                    if (empty($myMsgs)): echo "<p style='color:#aaa;'>No communication logs matching current signatures.</p>";
                    else: foreach($myMsgs as $m): 
                        $displaySender = ($m['from'] === SUPERUSER_ID) ? "System Administrator" : htmlspecialchars($m['from']);
                    ?>
                        <div style="border-bottom:1px solid #eee; padding-bottom:2px; margin-bottom:2px;">
                            <strong><?= $displaySender ?>:</strong> <?= htmlspecialchars($m['text']) ?>
                            <br><small style="color:#888;"><?= date('Y-m-d H:i', $m['timestamp']) ?></small>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                
                <form action="?route=send_msg" method="POST" style="margin-top:4px;">
                    <input type="hidden" name="to_user" value="<?= SUPERUSER_ID ?>">
                    <input type="text" name="message" placeholder="Type transmission to the system control node..." style="width:100%; margin-bottom:4px;" required autocomplete="off">
                    <input type="submit" value="Transmit Secure Message">
                </form>
            </div>
            <div class="col">
                <h2>Instantiate New Tracked Collection Index</h2>
                <form action="?route=manage_lists" method="POST">
                    <input type="hidden" name="sub_action" value="create">
                    <input type="text" name="list_name" required placeholder="Enter custom distinct listing title...">
                    <input type="submit" value="Instantiate Tracking Index" <?= $isBanned ? 'disabled' : '' ?>>
                </form>
            </div>
        </div>

        <h2>My Isolated Context Configurations Matrix</h2>
        <?php
        $myLists = $users[$currentUserId]['lists'] ?? [];
        if (empty($myLists)): echo "<p style='color:#666;'>No index data sets created under current profile token context.</p>";
        else: foreach($myLists as $lName => $lData): ?>
            <div style="background:#f1f2f3; border:1px solid #d1d5da; padding:6px; margin-bottom:6px;">
                <div style="display:flex; justify-content:between; align-items:center;">
                    <h3>📁 Compilation: <?= htmlspecialchars($lName) ?></h3>
                    <div style="margin-left:auto;">
                        <form action="?route=manage_lists" method="POST" style="display:inline;">
                            <input type="hidden" name="sub_action" value="rename"><input type="hidden" name="old_name" value="<?= htmlspecialchars($lName) ?>">
                            <input type="text" name="new_name" placeholder="Rename title profile..." style="width:120px; margin:0; font-size:10px;">
                            <input type="submit" value="Update Flag Title" <?= $isBanned ? 'disabled' : '' ?>>
                        </form>
                        <form action="?route=manage_lists" method="POST" style="display:inline; margin-left:4px;">
                            <input type="hidden" name="sub_action" value="delete"><input type="hidden" name="list_name" value="<?= htmlspecialchars($lName) ?>">
                            <input type="submit" value="Purge Set List" <?= $isBanned ? 'disabled' : '' ?> onclick="return confirm('Purge compilation reference context?');">
                        </form>
                    </div>
                </div>

                <form action="?route=manage_lists" method="POST" style="margin:4px 0; display:flex; flex-direction:column; gap:2px; background:#fff; padding:4px; border:1px solid #ccc;">
                    <input type="hidden" name="sub_action" value="add_item_to_list">
                    <input type="hidden" name="list_name" value="<?= htmlspecialchars($lName) ?>">
                    
                    <div style="position:relative;">
                        <label>Inject Target Node (Type to Autocomplete Search Database):</label>
                        <input type="text" class="autocomplete-trigger" data-type="item" placeholder="Search entity node target label..." required autocomplete="off">
                        <input type="hidden" name="internal_item_id" class="autocomplete-target">
                        <div class="ac-dropdown"></div>
                    </div>
                    
                    <input type="text" name="comment" placeholder="Annotation index notes..." style="margin:2px 0;">
                    <input type="submit" value="Bind Node Item to List Matrix" <?= $isBanned ? 'disabled' : '' ?>>
                </form>

                <table>
                    <tr><th>Linked Entity Target</th><th>Curator Annotation Remarks (Editable Inline)</th><th>Sequence Management</th><th>Operational Target Actions</th></tr>
                    <?php if (empty($lData['items'])): ?>
                        <tr><td colspan="4" style="color:#aaa; text-align:center;">No reference tokens added into this tracking directory layer.</td></tr>
                    <?php else: foreach($lData['items'] as $itId): $itObj = db_get_item($itId); ?>
                        <tr>
                            <td><a href="?id=<?= $itId ?>"><strong><?= htmlspecialchars($itObj['label'] ?? "Q$itId") ?></strong> (Q<?= $itId ?>)</a></td>
                            <td>
                                <form action="?route=manage_lists" method="POST" style="display:flex; gap:2px; margin:0; width:100%;">
                                    <input type="hidden" name="sub_action" value="update_comment">
                                    <input type="hidden" name="list_name" value="<?= htmlspecialchars($lName) ?>">
                                    <input type="hidden" name="item_id" value="<?= $itId ?>">
                                    <input type="text" name="comment" value="<?= htmlspecialchars($lData['comments'][$itId] ?? '') ?>" style="margin:0; font-size:10px; padding:1px 3px;">
                                    <input type="submit" value="Save" style="padding:1px 4px; font-size:10px;" <?= $isBanned ? 'disabled' : '' ?>>
                                </form>
                            </td>
                            <td>
                                <form action="?route=manage_lists" method="POST" style="display:inline;">
                                    <input type="hidden" name="sub_action" value="rearrange_item"><input type="hidden" name="list_name" value="<?= htmlspecialchars($lName) ?>"><input type="hidden" name="item_id" value="<?= $itId ?>">
                                    <button type="submit" name="dir" value="up" <?= $isBanned ? 'disabled' : '' ?>>▲</button>
                                    <button type="submit" name="dir" value="down" <?= $isBanned ? 'disabled' : '' ?>>▼</button>
                                </form>
                            </td>
                            <td>
                                <form action="?route=manage_lists" method="POST" style="display:inline;">
                                    <input type="hidden" name="sub_action" value="remove_item"><input type="hidden" name="list_name" value="<?= htmlspecialchars($lName) ?>"><input type="hidden" name="item_id" value="<?= $itId ?>">
                                    <input type="submit" class="action-btn" value="[Expel Element]" <?= $isBanned ? 'disabled' : '' ?>>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </table>
            </div>
        <?php endforeach; endif; ?>

    <?php
    // ROUTE: MASTER SUPERUSER TELEMETRY TERMINAL
    elseif ($viewRoute === 'admin' && $currentUserId === SUPERUSER_ID): ?>
        <h2>System Telemetry Monitoring Console Layer</h2>
        <div class="grid">
            <div class="col" style="flex:2;">
                <h2>Core Internal Process Actions Stream Log</h2>
                <div id="admin-logs-stream" style="max-height:360px; overflow-y:auto; font-family:monospace; background:#24292e; color:#a1cbfa; padding:4px; font-size:10px;">
                    </div>
                <div style="text-align: center; margin-top: 4px;">
                    <button id="load-more-logs-btn" onclick="fetchNextLogBatch()">Load Older Logs Feed ↓</button>
                </div>
            </div>
            <div class="col" style="flex:1;">
                <h2>Security Vector Inspection Node</h2>
                <?php
                $inspId = $_GET['inspect'] ?? '';
                if ($inspId !== '' && isset($users[$inspId])): $uObj = $users[$inspId]; 
                    $displayInspUser = ($inspId === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($inspId);
                ?>
                    <table style="background:#fff;">
                        <tr><th>Target Track</th><td><strong><?= $displayInspUser ?></strong></td></tr>
                        <tr><th>IP Interface</th><td><code><?= htmlspecialchars($uObj['ip']) ?></code></td></tr>
                        <tr><th>UA String</th><td style="font-size:9px; word-break:break-all;"><?= htmlspecialchars($uObj['ua']) ?></td></tr>
                        <tr><th>Ban Enforcement</th><td><?= !empty($uObj['banned']) ? '<span style="color:red;font-weight:bold;">Active Blockade</span>' : 'Clear Vector Channel' ?></td></tr>
                    </table>

                    <h3>Bidirectional Coordination Terminal</h3>
                    <div class="chat-container">
                        <?php
                        $sentToUser = $uObj['messages'] ?? [];
                        $repliesFromUser = [];
                        foreach (($users[SUPERUSER_ID]['messages'] ?? []) as $m) {
                            if ($m['from'] === $inspId) {
                                $repliesFromUser[] = $m;
                            }
                        }
                        $mergedChatStream = array_merge($sentToUser, $repliesFromUser);
                        uasort($mergedChatStream, function($a, $b) { return $a['timestamp'] <=> $b['timestamp']; });

                        if (empty($mergedChatStream)): echo "<p style='color:#aaa; text-align:center;'>No messages exchanged with this user node entry.</p>";
                        else: foreach ($mergedChatStream as $msgItem):
                            $isAdminSender = ($msgItem['from'] === SUPERUSER_ID);
                            $bubbleClass = $isAdminSender ? 'chat-outbound' : 'chat-inbound';
                            $senderTag = $isAdminSender ? 'Admin Outbound' : (($msgItem['from'] === SUPERUSER_ID) ? 'system administrator' : htmlspecialchars($msgItem['from']));
                        ?>
                            <div class="chat-bubble <?= $bubbleClass ?>">
                                <strong><?= $senderTag ?>:</strong> <?= htmlspecialchars($msgItem['text']) ?>
                                <br><small style="color:#666; font-size:8px;"><?= date('Y-m-d H:i', $msgItem['timestamp']) ?></small>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>

                    <form action="?route=send_msg" method="POST" style="margin-bottom:6px;">
                        <input type="hidden" name="to_user" value="<?= htmlspecialchars($inspId) ?>">
                        <textarea name="message" rows="2" placeholder="Input direct systemic directive or communication sequence..."></textarea>
                        <input type="submit" value="Transmit Secure Directive Message">
                    </form>

                    <h3>Apply Restriction Enforcement Directives</h3>
                    <form action="?route=admin_action" method="POST" style="margin-bottom:6px;">
                        <input type="hidden" name="target_user" value="<?= htmlspecialchars($inspId) ?>">
                        <select name="type" required>
                            <option value="ban_perm">Enforce Permanent Access Ban</option>
                            <option value="ban_temp">Enforce Temporary Access Quarantine (Minutes)</option>
                            <option value="unban">Restore Target Interface Credentials (Unban)</option>
                        </select>
                        <input type="text" name="duration" placeholder="Enter duration in minutes if temporary allocation..." style="margin-top:2px;">
                        <input type="submit" value="Execute Security Protocol Rule">
                    </form>
                <?php else: echo "<p style='color:#666;'>Select an active signature user link inside the telemetry dashboard feed to bind control mechanisms.</p>"; endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// INFINITE STREAM PAGINATION SUBSYSTEM FOR ITEMS NODE LISTING
let streamOffsetPosition = 0;
function fetchNextStreamBatch() {
    const tableBody = document.getElementById('items-stream-body');
    if (!tableBody) return;
    
    fetch('?api=load_items&offset=' + streamOffsetPosition)
        .then(response => response.json())
        .then(data => {
            if (data.length === 0) {
                document.getElementById('load-more-btn').innerText = "End of Matrix Ledger Registry Stream";
                document.getElementById('load-more-btn').disabled = true;
                return;
            }
            data.forEach(item => {
                const row = document.createElement('tr');
                row.innerHTML = '<td>Q' + item.id + '</td><td><a href="?id=' + item.id + '"><strong>' + escapeHtml(item.label) + '</strong></a></td>';
                tableBody.appendChild(row);
            });
            streamOffsetPosition += data.length;
        });
}

// ASYNCHRONOUS ENGINE FOR TELEMETRY ENGINE LOG RETRIEVAL
let logOffsetPosition = 0;
function fetchNextLogBatch() {
    const logContainer = document.getElementById('admin-logs-stream');
    if (!logContainer) return;

    fetch('?api=load_logs&offset=' + logOffsetPosition)
        .then(response => response.json())
        .then(data => {
            if (data.length === 0) {
                document.getElementById('load-more-logs-btn').innerText = "End of Log Data Packets";
                document.getElementById('load-more-logs-btn').disabled = true;
                return;
            }
            data.forEach(log => {
                const elementRow = document.createElement('div');
                let userDisplay = log.user_id === '<?= SUPERUSER_ID ?>' ? 'system administrator' : log.user_id;
                elementRow.innerHTML = '[' + log.date + '] <a href="?route=admin&inspect=' + encodeURIComponent(log.user_id) + '" style="color:#ffea7f; font-weight:bold;">' + escapeHtml(userDisplay) + '</a> -> ' + escapeHtml(log.action) + ': ' + escapeHtml(log.detail);
                logContainer.appendChild(elementRow);
            });
            logOffsetPosition += data.length;
        });
}

function escapeHtml(text) {
    return text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function toggleValueInputs(radioNode) {
    document.getElementById('wrapper-value-text').style.display = radioNode.value === 'text' ? 'block' : 'none';
    document.getElementById('wrapper-value-link').style.display = radioNode.value === 'link_internal' ? 'block' : 'none';
    document.getElementById('wrapper-value-wikidata').style.display = radioNode.value === 'link_wikidata' ? 'block' : 'none';
}

function toggleMatrixColumns() {
    const columns = document.querySelectorAll('.matrix-col');
    columns.forEach(col => {
        if (col.style.display === 'table-cell' || col.style.display === 'block') {
            col.style.display = 'none';
        } else {
            col.style.display = 'table-cell';
        }
    });
}

// AUTOCOMPLETE INITIALIZATION MAP
document.addEventListener("DOMContentLoaded", function() {
    if (document.getElementById('items-stream-body')) {
        fetchNextStreamBatch();
    }
    if (document.getElementById('admin-logs-stream')) {
        fetchNextLogBatch();
    }
    
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('show_matrix') === '1') {
        toggleMatrixColumns();
    }

    document.querySelectorAll('.autocomplete-trigger').forEach(inputField => {
        const dropBox = inputField.parentElement.querySelector('.ac-dropdown');
        const targetStore = inputField.parentElement.querySelector('.autocomplete-target');
        const extraStore = inputField.parentElement.querySelector('.autocomplete-extra-target');
        let debouncingTimer;

        inputField.addEventListener('input', function() {
            clearTimeout(debouncingTimer);
            const searchPhrase = this.value.trim();
            
            let actionType = 'suggest_item';
            const typeAttr = this.getAttribute('data-type');
            if (typeAttr === 'prop') {
                actionType = 'suggest_prop';
            } else if (typeAttr === 'wikidata') {
                actionType = 'suggest_wikidata';
            }

            if (searchPhrase.length < 1) {
                dropBox.style.display = 'none';
                return;
            }

            // Set dynamic delay depending on local vs remote search engine
            const delayTime = (actionType === 'suggest_wikidata') ? 320 : 180;

            debouncingTimer = setTimeout(() => {
                fetch('?api=' + actionType + '&q=' + encodeURIComponent(searchPhrase))
                    .then(res => res.json())
                    .then(matchesList => {
                        dropBox.innerHTML = '';
                        if (matchesList.length === 0) {
                            dropBox.style.display = 'none';
                            return;
                        }
                        matchesList.forEach(match => {
                            const optionRow = document.createElement('div');
                            optionRow.className = 'ac-item';
                            
                            let suffixId = (actionType === 'suggest_prop') ? match.id : ((actionType === 'suggest_wikidata') ? match.id : 'Q' + match.id);
                            let optionHTML = '<strong>' + escapeHtml(match.label) + '</strong> (' + escapeHtml(suffixId) + ')';
                            
                            if (match.desc) {
                                optionHTML += ' <span style="color:#666; font-size:10px; font-style:italic; margin-left:4px;">' + escapeHtml(match.desc) + '</span>';
                            }
                            
                            optionRow.innerHTML = optionHTML;
                            optionRow.addEventListener('click', function() {
                                inputField.value = match.label + ' (' + suffixId + ')';
                                targetStore.value = match.id;
                                if (extraStore) {
                                    extraStore.value = match.label;
                                }
                                dropBox.style.display = 'none';
                            });
                            dropBox.appendChild(optionRow);
                        });
                        dropBox.style.display = 'block';
                    });
            }, delayTime);
        });

        document.addEventListener('click', function(clickEvent) {
            if (!inputField.contains(clickEvent.target) && !dropBox.contains(clickEvent.target)) {
                dropBox.style.display = 'none';
            }
        });
    });
});
</script>
</body>
</html>
