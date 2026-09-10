<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
if (!hub_auth_is_authenticated()) { header('Location: /admin/login.php'); exit; }
if (!hub_auth_has_capability('football_ops')) { http_response_code(403); exit('Access denied.'); }
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/functions.php';
require_once __DIR__ . '/lib/pdf_importer.php';
require_once __DIR__ . '/lib/matchday_record.php';
pdf_import_schema($pdo);
matchday_record_ensure_schema($pdo);
$userId=(int)(hub_auth_current_user()['account_id']??hub_auth_current_user()['id']??0);
$id=(int)($_GET['id']??$_POST['id']??0);
$message=''; $error='';
if (isset($_GET['pdf'])) {
    try {
        $row=pdf_import_get($pdo,(int)$_GET['pdf']); $path=pdf_import_document_path($row);
        if (!hash_equals($row['sha256'],hash_file('sha256',$path))) throw new RuntimeException('This source file changed. Scan the new version first.');
        header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="match-report.pdf"');
        header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
        readfile($path); exit;
    } catch (Throwable $e) { http_response_code(404); exit(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }
}
if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $action=(string)($_POST['action']??'');
    $ajax=isset($_POST['ajax']);
    try {
        if (!csrf_check()) throw new RuntimeException('Your session expired. Reload the page and try again.');
        if ($action==='scan') { $result=pdf_import_scan($pdo); $message=$result['added'].' new PDFs queued; '.$result['duplicates'].' already known; '.$result['waiting'].' incomplete, oversized or invalid files skipped.'; }
        elseif ($action==='process') { session_write_close(); $worked=pdf_import_process_one($pdo); $projected=pdf_import_project_one($pdo); $message=($worked||$projected)?'Processed one report.':'Queue is up to date.'; }
        elseif ($action==='upload') {
            $file=$_FILES['pdf']??null;
            if (!$file || $file['error']!==UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) throw new RuntimeException('PDF upload failed.');
            if ($file['size']>PDF_IMPORT_MAX_BYTES || strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='pdf') throw new RuntimeException('Upload a PDF no larger than 20 MB.');
            $fh=fopen($file['tmp_name'],'rb'); $magic=fread($fh,5); fclose($fh);
            if ($magic!=='%PDF-') throw new RuntimeException('That file is not a PDF.');
            $name=preg_replace('/[^A-Za-z0-9 ._-]/','_',basename($file['name']));
            $name=substr(hash_file('sha256',$file['tmp_name']),0,12).'-'.substr($name,0,180);
            if (!is_dir(pdf_import_root()) && !mkdir(pdf_import_root(),0770,true)) throw new RuntimeException('Could not create PDF_imports.');
            if (!file_exists(pdf_import_root().'/'.$name) && !move_uploaded_file($file['tmp_name'],pdf_import_root().'/'.$name)) throw new RuntimeException('Could not save the PDF in PDF_imports.');
            // A completed HTTP upload is stable immediately.
            touch(pdf_import_root().'/'.$name,time()-11);
            pdf_import_scan($pdo); $message='PDF uploaded and queued.';
        }
        elseif ($action==='correct') { pdf_import_correct($pdo,$id,$_POST,$userId); $message='Corrections saved. Review fixture and player links again before importing.'; }
        elseif ($action==='review') { pdf_import_review($pdo,$id,$_POST,$userId); $message='Review saved. This report is ready to import.'; }
        elseif ($action==='apply') { $fixtureId=pdf_import_apply($pdo,$id,$userId); $message='Imported into fixture #'.$fixtureId.'. Display synchronization is queued.'; }
        elseif ($action==='undo') { if (empty($_POST['confirm_undo'])) throw new RuntimeException('Confirm undo first.'); pdf_import_undo($pdo,$id,$userId); $message='Import undone. Display synchronization is queued. Historical identities are retained.'; }
        elseif ($action==='retry') {
            $row=pdf_import_get($pdo,$id);
            if (in_array($row['status'],['pending','undo_pending'],true)) $pdo->prepare('UPDATE historical_pdf_imports SET error=NULL WHERE id=?')->execute([$id]);
            elseif (in_array($row['status'],['failed','skipped','review','ready','undone'],true)) $pdo->prepare("UPDATE historical_pdf_imports SET status='queued',review_json=NULL,error=NULL WHERE id=?")->execute([$id]);
            else throw new RuntimeException('This report cannot be retried in its current state.');
            $message='Report queued for retry.';
        }
        elseif ($action==='skip') { $pdo->prepare("UPDATE historical_pdf_imports SET status='skipped' WHERE id=? AND status IN ('queued','review','ready','failed')")->execute([$id]); $message='Report skipped.'; }
        else throw new RuntimeException('Unknown action.');
        if ($ajax) { header('Content-Type: application/json'); echo pdf_import_json(['ok'=>true,'message'=>$message]); exit; }
        $_SESSION['pdf_import_notice']=$message;
        header('Location: /admin/pdf_importer.php'.($id?'?id='.$id:'')); exit;
    } catch (Throwable $e) {
        $error=$e->getMessage();
        if ($ajax) { http_response_code(422); header('Content-Type: application/json'); echo pdf_import_json(['ok'=>false,'message'=>$error]); exit; }
    }
}
$message=$message ?: ($_SESSION['pdf_import_notice']??''); unset($_SESSION['pdf_import_notice']);
$pageHero=['eyebrow'=>'Overview · Temporary tool','title'=>'PDF Importer','subtitle'=>'Review historical match reports, match fixtures and build a richer results archive.','actions'=>[]];
require_once __DIR__.'/header.php';
$esc=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$counts=$pdo->query('SELECT status,COUNT(*) FROM historical_pdf_imports GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<style>
.pdf-import-grid{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,.8fr);gap:1.25rem}.pdf-import-preview{width:100%;height:650px;border:1px solid #ddd;border-radius:8px}.pdf-import-actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:center}.pdf-import-table td{vertical-align:middle}.pdf-import-source{white-space:pre-wrap;max-height:420px;overflow:auto;font-size:.85rem}.pdf-import-player{display:grid;grid-template-columns:1fr 1.4fr;gap:.75rem;margin-bottom:.6rem}.pdf-import-event{border-bottom:1px solid #eee;padding:.5rem 0}.pdf-import-meta{max-height:350px;overflow:auto}@media(max-width:1000px){.pdf-import-grid{grid-template-columns:1fr}.pdf-import-preview{height:450px}.pdf-import-player{grid-template-columns:1fr}}
</style>
<?php if($message):?><div class="alert alert-success" role="status"><?=$esc($message)?></div><?php endif;?>
<?php if($error):?><div class="alert alert-danger" role="alert"><?=$esc($error)?></div><?php endif;?>
<div id="pdfProgress" class="alert alert-info d-none" role="status" aria-live="polite"></div>
<div class="card mb-4"><div class="card-body">
<div class="pdf-import-actions">
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="scan"><button class="btn btn-brand">Scan PDF_imports</button></form>
<button type="button" class="btn btn-outline-primary" id="processQueue">Process pending reports (<?= (int)($counts['queued']??0) ?>)</button>
<label class="btn btn-outline-secondary mb-0">Upload PDFs<input id="pdfUploads" type="file" accept="application/pdf,.pdf" multiple hidden></label>
<a href="/admin/pdf_importer.php" class="btn btn-outline-secondary">All reports</a>
</div>
<p class="text-muted small mt-3 mb-0">Add PDFs to PDF_imports or upload them here (20 MB per file). New files are extracted in the background. Match records change only when you import reviewed reports.</p>
</div></div>
<?php if($id):
try {
$row=pdf_import_get($pdo,$id);
$report=$row['report_json']?json_decode($row['report_json'],true):null;
$review=$row['review_json']?json_decode($row['review_json'],true):[];
?>
<div class="d-flex justify-content-between align-items-center mb-3"><h2 class="h4 mb-0"><?=$esc($row['source_name'])?></h2><span class="badge bg-secondary"><?=$esc($row['status'])?></span></div>
<?php if($row['error']):?><div class="alert alert-warning"><?=$esc($row['error'])?></div><?php endif;?>
<?php if($report):
$candidates=pdf_import_candidates($pdo,$report);
$exact=array_values(array_filter($candidates,static fn($f)=>$f['exact']));
$selected=(int)($_GET['fixture']??$review['fixture_id']??(count($exact)===1?$exact[0]['id']:0));
$snapshot=pdf_import_snapshot($pdo,$selected);
if($selected && $snapshot['fixture'] && !in_array($selected,array_map(static fn($f)=>(int)$f['id'],$candidates),true)) { $extra=$snapshot['fixture']; $extra['exact']=false; $candidates[]=$extra; }
$seasons=$pdo->query('SELECT * FROM seasons ORDER BY start_date DESC,id DESC')->fetchAll();
$seasonId=(int)($snapshot['fixture']['season_id']??$review['season_id']??0);
if(!$seasonId)foreach($seasons as $s)if($s['start_date']<=$report['match_date']&&$s['end_date']>=$report['match_date']){$seasonId=(int)$s['id'];break;}
$playerOptions=pdf_import_player_options($pdo,$report);
$validation=pdf_import_validate($report);
?>
<div class="pdf-import-grid">
<div>
<div class="card mb-3"><div class="card-body">
<h3 class="h5"><?=$esc($report['home_team'])?> <?= $esc(implode(' – ',$report['score'])) ?> <?=$esc($report['away_team'])?></h3>
<p><?=$esc($report['match_date'])?> · <?=$esc($report['kickoff'])?> · <?=$esc($report['competition'])?><br><?=$esc($report['metadata']['venue'])?> · <?=$esc($report['stage'])?></p>
<?php foreach(array_merge($validation,$report['warnings']) as $warning):?><div class="alert alert-warning"><?=$esc($warning)?></div><?php endforeach;?>
<?php if(in_array($row['status'],['review','ready'],true)):?>
<form method="post" id="reviewForm">
<?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="review">
<input type="hidden" name="preview_fingerprint" value="<?=$esc(pdf_import_fingerprint($snapshot))?>">
<label class="form-label fw-semibold" for="fixtureChoice">Match this report to</label>
<select class="form-select mb-2" name="fixture_id" id="fixtureChoice" data-import-id="<?=$id?>">
<option value="0" <?=$selected===0?'selected':''?>>Create a missing historical fixture</option>
<?php foreach($candidates as $f):?><option value="<?=(int)$f['id']?>" <?=$selected===(int)$f['id']?'selected':''?>>#<?=(int)$f['id']?> · <?=$esc($f['match_date'].' · '.$f['opponent'].' · '.($f['is_home']?'Home':'Away').' · '.$f['competition'])?><?=$f['exact']?' — date, opponent and venue side match':''?></option><?php endforeach;?>
</select>
<p class="small text-muted">Candidates within seven days are shown. Date, opponent and home/away matches are suggested; check the competition before approving.</p>
<label for="manualFixture" class="form-label small">Find another fixture by its ID</label>
<div class="input-group mb-3"><input type="number" min="1" id="manualFixture" class="form-control" placeholder="Fixture ID"><button type="button" class="btn btn-outline-secondary" id="findFixture" data-import-id="<?=$id?>">View fixture</button></div>
<?php if($snapshot['fixture']):$f=$snapshot['fixture'];?>
<div class="alert alert-light border"><strong>Existing record #<?=$selected?></strong><br><?=$esc($f['match_date'].' · '.$f['opponent'].' · '.$f['competition'])?><br>Score: <?=$esc(($f['full_time_home_score']??'?').' – '.($f['full_time_away_score']??'?'))?> · <?=count($snapshot['matchday_lineups'])?> lineup entries · <?=count($snapshot['matchday_events'])?> events<br><a href="/admin/match.php?id=<?=$selected?>" target="_blank" rel="noopener">Compare existing match ↗</a>
<details class="mt-2"><summary>Existing players and events</summary><ul class="mb-0"><?php foreach($snapshot['matchday_lineups'] as $p):?><li><?=$esc($p['side'].' · '.$p['player_name'].($p['is_starting']?' (starts)':' (bench)'))?></li><?php endforeach;?><?php foreach($snapshot['matchday_events'] as $e):?><li><?=$esc($e['minute'].($e['minute_extra']?'+'.$e['minute_extra']:'').' · '.$e['type'].' · '.$e['player_name'])?></li><?php endforeach;?></ul></details></div>
<label class="d-block mb-3"><input type="checkbox" name="match_override" value="1"> I checked any date/opponent spelling differences against the PDF.</label>
<?php endif;?>
<label class="form-label" for="seasonChoice">Season</label><select class="form-select mb-2" id="seasonChoice" name="season_id"><?php foreach($seasons as $s):?><option value="<?=(int)$s['id']?>" <?=$seasonId===(int)$s['id']?'selected':''?>><?=$esc($s['name'])?><?=$s['is_locked']?' (locked)':''?></option><?php endforeach;?></select>
<?php if(!$selected):?><label class="d-block mb-2"><input type="checkbox" name="new_season" value="1"> Create the historical season from the report date if it is missing (July–June).</label><?php endif;?>
<label class="d-block mb-3"><input type="checkbox" name="locked_ok" value="1" <?=!empty($review['locked_ok'])?'checked':''?>> Allow this reviewed historical import into a locked season, without unlocking it.</label>
<label class="form-label fw-semibold" for="importMode">Existing match information</label>
<select class="form-select mb-2" name="mode" id="importMode">
<?php foreach(['missing'=>'Add the full report only if the match record is empty','metadata'=>'Preserve the existing lineup/events; retain this source and fill missing fixture details','replace'=>'Replace both teams’ lineups and events with this reviewed report'] as $value=>$label):?><option value="<?=$value?>" <?=($review['mode']??'missing')===$value?'selected':''?>><?=$esc($label)?></option><?php endforeach;?>
</select>
<label class="d-block mb-3"><input type="checkbox" name="score_override" value="1" <?=!empty($review['score_override'])?'checked':''?>> Replace an existing result with the official PDF score. Otherwise existing scores are preserved.</label>
<h4 class="h6 mt-4">Link Saltcoats players across seasons</h4>
<p class="text-muted small">Inactive players are included. “Create historical player” creates an inactive record. COMET registration IDs remember reviewed links for later reports.</p>
<?php foreach($playerOptions['options'] as $registration=>$option): $choice=(string)($review['players'][$registration]??$option['selected']);?>
<div class="pdf-import-player"><label for="player<?=$esc($registration)?>"><?=$esc($option['source']['name'])?><small class="d-block text-muted">#<?=$esc($option['source']['number'])?> · <?=$option['source']['starting']?'Starter':'Bench'?> · <?=$esc($option['method'])?></small></label>
<select class="form-select form-select-sm" id="player<?=$esc($registration)?>" name="players[<?=$esc($registration)?>]"><option value="">Choose a player…</option><option value="new" <?=$choice==='new'?'selected':''?>>Create historical player</option><?php foreach($playerOptions['players'] as $p):?><option value="<?=(int)$p['id']?>" <?=$choice===(string)$p['id']?'selected':''?>><?=$esc($p['name'])?><?=$p['active']?'':' (inactive)'?> · #<?=(int)$p['id']?></option><?php endforeach;?></select></div>
<?php endforeach;?>
<label class="d-block mt-4 mb-3"><input type="checkbox" name="reviewed" value="1" required> I have checked the PDF, fixture, player links and the import choices above.</label>
<button class="btn btn-brand" <?=$validation||$report['warnings']?'disabled':''?>>Save review — mark ready</button>
</form>
<?php endif;?>
</div></div>
<div class="card mb-3"><div class="card-body"><h3 class="h5">Both teams’ lineups</h3><p class="small text-muted"><?=$esc($report['coverage']??'Recorded match details')?></p>
<?php foreach($report['lineups'] as $side=>$players):?><h4 class="h6 mt-3"><?=$side==='svfc'?'Saltcoats Victoria':$esc($report['opponent'])?></h4><ul><?php foreach($players as $p):?><li><?=$esc($p['number'].' · '.$p['name'])?> — <?=$p['starting']?'Starter':'Bench'?><?=$p['marker']?' · '.$esc($p['marker']):''?><small class="text-muted"> · COMET <?=$esc($p['registration_id'])?></small></li><?php endforeach;?></ul><?php endforeach;?>
<p class="small text-muted mb-0">A bench selection is not an appearance. Only starters and confirmed substitute entries count as playing.</p></div></div>
</div>
<div>
<a class="btn btn-outline-secondary btn-sm mb-2" href="?pdf=<?=$id?>" target="_blank" rel="noopener">Open original PDF ↗</a>
<iframe title="Original match report PDF" class="pdf-import-preview" src="?pdf=<?=$id?>"></iframe>
<div class="card mt-3"><div class="card-body"><h3 class="h5">Extracted events (<?=count($report['events'])?>)</h3>
<?php $events=$report['events'];usort($events,static fn($a,$b)=>pdf_import_minute_sort($a['minute'])<=>pdf_import_minute_sort($b['minute']));foreach($events as $e):?>
<div class="pdf-import-event"><strong><?=$esc($e['minute'])?>′ · <?=$esc(str_replace('_',' ',$e['type']))?></strong> · <?=$e['side']==='svfc'?'Saltcoats':$esc($report['opponent'])?><br><?=$esc($e['name'])?><?=isset($e['on_name'])?' → '.$esc($e['on_name']):''?><?php if($e['note']):?><details><summary>Report detail</summary><?=$esc($e['note'])?></details><?php endif;?></div>
<?php endforeach;?></div></div>
<div class="card mt-3"><div class="card-body"><h3 class="h5">Other report details</h3><p>Attendance: <?=$esc($report['metadata']['attendance']?:'Not recorded')?> · Match number: <?=$esc($report['metadata']['match_number'])?></p><details><summary>Officials, staff and confirmations</summary><div class="pdf-import-meta"><?php foreach(['MATCH OFFICIALS','STAFF','CONFIRMATIONS','REPORT METADATA'] as $section):?><h4 class="h6 mt-3"><?=$esc($section)?></h4><?php foreach($report['metadata'][$section]??[] as $side=>$blocks):?><p class="small"><?php foreach($blocks as $block):?><?=$esc($block['text'])?><br><?php endforeach;?></p><?php endforeach;?><?php endforeach;?></div></details><details class="mt-2"><summary>All extracted source text</summary><pre class="pdf-import-source"><?=$esc($report['raw_text'])?></pre></details></div></div>
</div></div>
<?php if(in_array($row['status'],['review','ready'],true)) require __DIR__.'/partials/pdf_import_corrections.php'; ?>
<?php endif;?>
<div class="card mt-3 mb-4"><div class="card-body pdf-import-actions">
<?php if($row['status']==='ready'):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="apply"><button class="btn btn-success">Import this reviewed report</button></form><?php endif;?>
<?php if($row['fixture_id']):?><a href="/admin/match.php?id=<?=(int)$row['fixture_id']?>" class="btn btn-outline-primary">Open fixture #<?=(int)$row['fixture_id']?></a><?php endif;?>
<?php if($row['status']==='imported'):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="undo"><label class="me-2"><input type="checkbox" name="confirm_undo" value="1" required> Restore the match to before this import</label><button class="btn btn-outline-danger">Undo import</button></form><?php else:?>
<?php if(!in_array($row['status'],['pending','undo_pending'],true)||$row['error']):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="retry"><button class="btn btn-outline-secondary"><?=in_array($row['status'],['pending','undo_pending'],true)?'Retry display synchronization':'Re-extract / retry'?></button></form><?php endif;?>
<?php endif;?>
<?php if(in_array($row['status'],['review','ready','queued','failed'],true)):?><form method="post"><?=csrf_field()?><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="action" value="skip"><button class="btn btn-outline-secondary">Skip report</button></form><?php endif;?>
</div></div>
<?php }catch(Throwable $e){?><div class="alert alert-danger"><?=$esc($e->getMessage())?></div><?php } ?>
<?php else:
$filter=(string)($_GET['status']??'');$page=max(1,(int)($_GET['page']??1));
$where=$filter!==''?' WHERE status=?':'';
$st=$pdo->prepare('SELECT COUNT(*) FROM historical_pdf_imports'.$where);$st->execute($filter!==''?[$filter]:[]);$total=(int)$st->fetchColumn();
$st=$pdo->prepare('SELECT id,source_name,status,fixture_id,error,report_json,imported_at FROM historical_pdf_imports'.$where.' ORDER BY id DESC LIMIT 100 OFFSET '.(($page-1)*100));$st->execute($filter!==''?[$filter]:[]);
?>
<div class="pdf-import-actions mb-3"><a href="?" class="btn btn-sm btn-outline-secondary">All</a><?php foreach($counts as $status=>$count):?><a href="?status=<?=$esc($status)?>" class="btn btn-sm btn-outline-secondary"><?=$esc(ucfirst($status))?> (<?=(int)$count?>)</a><?php endforeach;?></div>
<div class="card"><div class="card-body">
<div class="pdf-import-actions mb-3"><button id="importSelected" class="btn btn-success" type="button">Import selected ready reports</button><label><input type="checkbox" id="selectReady"> Select ready reports on this page</label></div>
<div class="table-responsive"><table class="table pdf-import-table"><thead><tr><th scope="col">Select</th><th scope="col">PDF</th><th scope="col">Match</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead><tbody>
<?php $any=false;foreach($st as $row):$any=true;$report=$row['report_json']?json_decode($row['report_json'],true):null;?>
<tr><td><?php if($row['status']==='ready'):?><input type="checkbox" class="readyReport" value="<?=(int)$row['id']?>" aria-label="Select <?=$esc($row['source_name'])?>"><?php endif;?></td><td><?=$esc($row['source_name'])?><?php if($row['error']):?><small class="d-block text-danger"><?=$esc($row['error'])?></small><?php endif;?></td><td><?=$report?$esc($report['match_date'].' · '.$report['opponent'].' · '.($report['is_home']?'Home':'Away')):'Awaiting extraction'?></td><td><?=$esc($row['status'])?></td><td><a class="btn btn-sm btn-outline-primary" href="?id=<?=(int)$row['id']?>"><?=$row['status']==='imported'?'View import':'Review'?></a></td></tr>
<?php endforeach;if(!$any):?><tr><td colspan="5" class="text-muted py-4">No reports here yet. Scan PDF_imports to begin.</td></tr><?php endif;?></tbody></table></div>
<?php if($page>1):?><a class="btn btn-sm btn-outline-secondary" href="?page=<?=$page-1?>&amp;status=<?=$esc($filter)?>">Previous</a><?php endif;?><?php if($page*100<$total):?><a class="btn btn-sm btn-outline-secondary" href="?page=<?=$page+1?>&amp;status=<?=$esc($filter)?>">Next</a><?php endif;?>
</div></div>
<?php endif;?>
<form id="pdfToken" hidden><?=csrf_field()?></form>
<script src="/admin/assets/js/pdf-importer.js?v=1" defer></script>
<?php require_once __DIR__.'/footer.php'; ?>
