<?php
session_start();

//The flash message helper 
if (!isset($_SESSION['flash'])) $_SESSION['flash'] = null;
function set_flash($message, $type = 'success') {
    $_SESSION['flash'] = ['msg' => $message, 'type' => $type];
}
function get_flash() {
    $f = $_SESSION['flash'] ?? null;
    $_SESSION['flash'] = null;
    return $f;
}

// --- Framework definition ---
//Cost/Benefit: true=cost, false=benefit. Cost means that lower values are better.
$framework = [
    'Privacy' => [
        ['id'=>'linking','label'=>'Linking','type'=>'o','cost'=>true], //cost=true. Privacy metrics should be cost.
        ['id'=>'identifying','label'=>'Identifying','type'=>'o','cost'=>true], //cost
        ['id'=>'non_repudiation','label'=>'Non-repudiation','type'=>'o','cost'=>true],
        ['id'=>'detecting','label'=>'Detecting','type'=>'o','cost'=>true],
        ['id'=>'data_disclosure','label'=>'Data Disclosure','type'=>'o','cost'=>true], //cost
        ['id'=>'unawareness','label'=>'Unawareness','type'=>'s','cost'=>true],
        ['id'=>'non_compliance','label'=>'Non-compliance','type'=>'o','cost'=>true], //cost
    ],
    'Reliability' => [
        ['id'=>'faultlessness','label'=>'Faultlessness','type'=>'o','cost'=>false],
        ['id'=>'availability','label'=>'Availability','type'=>'o','cost'=>false],
        ['id'=>'fault_tolerance','label'=>'Fault Tolerance','type'=>'o','cost'=>false],
        ['id'=>'recoverability','label'=>'Recoverability','type'=>'o','cost'=>false],
    ],
    'Robustness' => [
        ['id'=>'adaptability','label'=>'Adaptability','type'=>'o','cost'=>false],
        ['id'=>'resilience','label'=>'Resilience','type'=>'o','cost'=>false],
    ],
    'Security' => [
        ['id'=>'confidentiality','label'=>'Confidentiality','type'=>'o','cost'=>false],
        ['id'=>'integrity','label'=>'Integrity','type'=>'o','cost'=>false],
        ['id'=>'availability_sec','label'=>'Availability (sec)','type'=>'o','cost'=>false],
        ['id'=>'authenticity','label'=>'Authenticity','type'=>'o','cost'=>false],
    ],
    'Reproducibility' => [
        ['id'=>'data_reproducibility','label'=>'Data Reproducibility','type'=>'o','cost'=>false],
        ['id'=>'method_reproducibility','label'=>'Method Reproducibility','type'=>'o','cost'=>false],
        ['id'=>'results_reproducibility','label'=>'Results Reproducibility','type'=>'o','cost'=>false],
    ],
    'Accountability' => [
        ['id'=>'auditability','label'=>'Auditability','type'=>'o','cost'=>false],
        ['id'=>'non-repudiation_acct','label'=>'Non-repudiation (Acct)','type'=>'o','cost'=>false],
    ],
    'Fairness' => [
        ['id'=>'substantive_fairness','label'=>'Substantive Fairness','type'=>'o','cost'=>false],
        ['id'=>'perceived_fairness','label'=>'Perceived Fairness','type'=>'s','cost'=>false],
    ],
    'Transparency' => [
        ['id'=>'explainability','label'=>'Explainability','type'=>'o','cost'=>false],
        ['id'=>'traceability','label'=>'Traceability','type'=>'o','cost'=>false],
    ],
    'Human Agency' => [
        ['id'=>'learnability','label'=>'Learnability','type'=>'s','cost'=>false],
        ['id'=>'operability','label'=>'Operability','type'=>'s','cost'=>false],
        ['id'=>'human_autonomy','label'=>'Human Autonomy','type'=>'s','cost'=>false],
    ],
    'Functional Suitability' => [
        ['id'=>'functional_correctness','label'=>'Functional Correctness','type'=>'o','cost'=>false],
        ['id'=>'functional_completeness','label'=>'Functional Completeness','type'=>'o','cost'=>false],
        ['id'=>'functional_appropriateness','label'=>'Functional Appropriateness','type'=>'o','cost'=>false],
    ],
    'Safety' => [
        ['id'=>'human_safety','label'=>'Human Safety','type'=>'s','cost'=>false],
        ['id'=>'societal_safety','label'=>'Societal Safety','type'=>'s','cost'=>false],
    ],
];

// flatten metrics
$metrics = [];
foreach($framework as $qa=>$list){
    foreach($list as $m){
        $m['qa'] = $qa;
        $metrics[$m['id']] = $m;
    }
}

// init session containers
if(!isset($_SESSION['weights'])) $_SESSION['weights'] = [];
if(!isset($_SESSION['systems'])) $_SESSION['systems'] = [];
if(!isset($_SESSION['qa_weights'])) $_SESSION['qa_weights'] = [];

// ---------------------
// NEW: handle save_exports (JSON POST) when called with ?action=save_exports
// ---------------------
if( (isset($_GET['action']) && $_GET['action'] === 'save_exports') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // read raw JSON
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if(!$payload){
        http_response_code(400);
        echo json_encode(['ok'=>false,'msg'=>'Invalid JSON payload']);
        exit;
    }

    // expected keys: images: {tw: base64, objsub: base64, metric: base64}, qa_table: {headers:[], rows:[[]]}, metric_table similar
    // Save images
    $saved = [];
    if(isset($payload['images']) && is_array($payload['images'])){
        foreach(['tw','objsub','metric'] as $k){
            if(!empty($payload['images'][$k])){
                // payload contains dataURL without prefix or with prefix; handle both
                $datauri = $payload['images'][$k];
                if(strpos($datauri,'base64,') !== false){
                    $b64 = substr($datauri, strpos($datauri,'base64,')+7);
                } else {
                    $b64 = $datauri;
                }
                $bin = base64_decode($b64);
                $filename = __DIR__ . DIRECTORY_SEPARATOR . ($k . '.png');
                $w = file_put_contents($filename, $bin, LOCK_EX);
                $saved[$k] = $w !== false;
            } else $saved[$k] = false;
        }
    }

    // Save LaTeX for QA table
    $latex_files = [];
    if(isset($payload['qa_table']) && is_array($payload['qa_table'])){
        $qa = $payload['qa_table'];
        $filename = __DIR__ . DIRECTORY_SEPARATOR . 'qa_table.tex';
        $tex = build_latex_table($qa['headers'] ?? [], $qa['rows'] ?? []);
        $ok = file_put_contents($filename, $tex, LOCK_EX) !== false;
        $latex_files['qa_table'] = $ok;
    }

    // Save LaTeX for metric table
    if(isset($payload['metric_table']) && is_array($payload['metric_table'])){
        $mt = $payload['metric_table'];
        $filename = __DIR__ . DIRECTORY_SEPARATOR . 'metric_table.tex';
        $tex = build_latex_table($mt['headers'] ?? [], $mt['rows'] ?? []);
        $ok = file_put_contents($filename, $tex, LOCK_EX) !== false;
        $latex_files['metric_table'] = $ok;
    }

    echo json_encode(['ok'=>true,'images'=>$saved,'latex'=>$latex_files]);
    exit;
}

// helper function to escape latex special chars
function latex_escape($s){
    $map = ['\\'=>'\\textbackslash{}','%'=>'\\%','$'=>'\\$','#'=>'\\#','_'=>'\\_','&'=>'\\&','{'=>'\\{','}'=>'\\}','~'=>'\\textasciitilde{}','^'=>'\\textasciicircum{}'];
    return strtr($s, $map);
}

// build LaTeX tabular from headers and rows (simple)
function build_latex_table($headers, $rows){
    // headers: array of strings
    // rows: array of arrays (same length)
    $cols = max(1, count($headers));
    $colspec = str_repeat('l', $cols);
    $tex = "\\begin{table}[ht]\n\\centering\n";
    $tex .= "\\begin{tabular}{" . $colspec . "}\n\\hline\n";
    // header line
    $hline = array_map('latex_escape', $headers);
    $tex .= implode(' & ', $hline) . " \\\\ \\hline\n";
    foreach($rows as $r){
        $cells = array_map(function($c){ return latex_escape((string)$c); }, $r);
        $tex .= implode(' & ', $cells) . " \\\\ \n";
    }
    $tex .= "\\hline\n\\end{tabular}\n\\caption{Exported table}\n\\end{table}\n";
    return $tex;
}

// ---------------------
// remaining POST handlers
// ---------------------
$action = $_POST['action'] ?? null;

if($action === 'set_num_systems'){
    $n = max(1,intval($_POST['num_systems'] ?? 1));
    $_SESSION['systems'] = [];
    for($i=0;$i<$n;$i++){
        $_SESSION['systems'][] = ['name'=>'System '.($i+1),'objective'=>[],'subjective'=>[]];
    }
    set_flash('Number of systems updated.', 'success');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

if($action === 'save_weights'){
    foreach($metrics as $id=>$m){
        $w = intval($_POST['w_'.$id] ?? 0);
        if($w<0) $w=0; if($w>9) $w=9;
        $_SESSION['weights'][$id] = $w;
    }
    foreach(array_keys($framework) as $qa){
        $key = 'qa_w_'.preg_replace('/\s+/','_',$qa);
        $wq = floatval($_POST[$key] ?? 1);
        $_SESSION['qa_weights'][$qa] = $wq;
    }
    set_flash('Weights saved.', 'success');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

if($action === 'save_subjective_scores'){
    foreach($_SESSION['systems'] as $idx=>$s){
        foreach($metrics as $id=>$m){
            if($m['type'] === 's'){
                $key = "sub_{$id}_{$idx}";
                $val = intval($_POST[$key] ?? 0);
                $_SESSION['systems'][$idx]['subjective'][$id] = max(0,min(9,$val));
            }
        }
        $_SESSION['systems'][$idx]['name'] = substr($_POST['system_name_'.$idx] ?? $_SESSION['systems'][$idx]['name'],0,64);
    }
    set_flash('Subjective scores saved.', 'success');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

if($action === 'upload_objective_csv'){
    if(!empty($_FILES['objective_csv']['tmp_name'])){
        $csv = array_map('str_getcsv', file($_FILES['objective_csv']['tmp_name']));
        if(count($csv)>0){
            $headers = array_map(function($h){ return trim((string)$h); }, $csv[0]);
            $rows = array_slice($csv,1);

            $firstHeader = strtolower($headers[0] ?? '');
            $knownMetricIds = array_map('strtolower', array_keys($metrics));
            $is_metrics_as_rows = false;
            if($firstHeader === 'metric' || !in_array($firstHeader, $knownMetricIds)){
                $is_metrics_as_rows = true;
            }

            $_SESSION['systems'] = [];

            if($is_metrics_as_rows){
                $numSystems = max(0, count($headers)-1);
                for($si=0;$si<$numSystems;$si++){
                    $sysName = $headers[$si+1] !== '' ? $headers[$si+1] : 'System '.($si+1);
                    $_SESSION['systems'][] = ['name'=>$sysName,'objective'=>[],'subjective'=>[]];
                }
                foreach($rows as $r){
                    if(!isset($r[0])) continue;
                    $metricIdRaw = trim($r[0]);
                    if($metricIdRaw === '') continue;
                    $metricId = $metricIdRaw;
                    if(!isset($metrics[$metricId])){
                        $alt = str_replace([' ','-'],['_','_'],strtolower($metricIdRaw));
                        if(isset($metrics[$alt])) $metricId = $alt;
                        else {
                            $alt2 = preg_replace('/[^a-z0-9_]/','', $alt);
                            if(isset($metrics[$alt2])) $metricId = $alt2;
                        }
                    }
                    for($si=0;$si<$numSystems;$si++){
                        $val = isset($r[$si+1]) ? trim($r[$si+1]) : '';
                        $_SESSION['systems'][$si]['objective'][$metricId] = is_numeric($val) ? floatval($val) : null;
                    }
                }
            } else {
                foreach($rows as $ridx=>$row){
                    $row = array_map('trim',$row);
                    $sysName = 'System '.($ridx+1);
                    if(isset($headers[0]) && in_array(strtolower($headers[0]), ['name','system','system_name']) && isset($row[0]) && $row[0] !== '') $sysName = $row[0];
                    $sys = ['name'=>$sysName,'objective'=>[],'subjective'=>[]];
                    foreach($headers as $hidx=>$h){
                        $h_trim = trim($h);
                        if($h_trim === '') continue;
                        if(isset($metrics[$h_trim])){
                            $sys['objective'][$h_trim] = isset($row[$hidx]) && is_numeric($row[$hidx]) ? floatval($row[$hidx]) : null;
                        } else {
                            $alt = str_replace([' ','-'],['_','_'],strtolower($h_trim));
                            if(isset($metrics[$alt])){
                                $sys['objective'][$alt] = isset($row[$hidx]) && is_numeric($row[$hidx]) ? floatval($row[$hidx]) : null;
                            }
                        }
                    }
                    $_SESSION['systems'][] = $sys;
                }
            }
            set_flash('Objective CSV uploaded and parsed.', 'success');
        } else {
            set_flash('Uploaded CSV seems empty.', 'warning');
        }
    } else {
        set_flash('No file uploaded.', 'warning');
    }
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

// manual objective save
if($action === 'save_objective_manual'){
    foreach($_SESSION['systems'] as $idx=>$_){
        foreach($metrics as $id=>$m){
            if($m['type']!=='o') continue;
            $key = "obj_{$id}_{$idx}";
            if(isset($_POST[$key]) && $_POST[$key] !== ''){
                $v = trim($_POST[$key]);
                $_SESSION['systems'][$idx]['objective'][$id] = is_numeric($v)?floatval($v):null;
            } else {
                if(!isset($_SESSION['systems'][$idx]['objective'][$id])) $_SESSION['systems'][$idx]['objective'][$id] = null;
            }
        }
    }
    set_flash('Objective values saved.', 'success');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

if($action === 'clear_all'){
    unset($_SESSION['weights']); unset($_SESSION['systems']); unset($_SESSION['qa_weights']);
    session_destroy();
    session_start();
    set_flash('Session cleared.', 'info');
    header('Location: '.$_SERVER['PHP_SELF']); exit;
}

// ensure default systems exist
if(empty($_SESSION['systems'])){
    $_SESSION['systems'] = [];
    for($i=0;$i<3;$i++) $_SESSION['systems'][] = ['name'=>'System '.($i+1),'objective'=>[],'subjective'=>[]];
}

// scoring function (returning metric_contribs)
function compute_scores($metrics,$framework){
    $systems = &$_SESSION['systems'];
    $weights = $_SESSION['weights'] ?? [];
    $qa_weights = $_SESSION['qa_weights'] ?? [];

    $active_objective = []; $active_subjective = [];
    foreach($metrics as $id=>$m){
        $w = $weights[$id] ?? 0;
        if($w==0) continue;
        if($m['type']==='o') $active_objective[$id]=$m;
        else $active_subjective[$id]=$m;
    }

    $nSystems = count($systems);

    // objective raw values
    $obj_values = [];
    foreach($active_objective as $id=>$m){
        $arr = [];
        for($i=0;$i<$nSystems;$i++){
            $val = $systems[$i]['objective'][$id] ?? null;
            $arr[] = is_numeric($val) ? floatval($val) : null;
        }
        $obj_values[$id] = $arr;
    }

    // normalize objective
    $obj_norm = [];
    foreach($obj_values as $id=>$vals){
        $valid = array_filter($vals,function($v){return $v!==null;});
        if(empty($valid)){
            $obj_norm[$id] = array_fill(0,$nSystems,0.0);
            continue;
        }
        $min = min($valid); $max = max($valid);
        $den = ($max-$min)==0?1:($max-$min);
        for($i=0;$i<$nSystems;$i++){
            $v = $vals[$i];
            if($v===null) { $obj_norm[$id][$i]=0.0; continue; }
            if($metrics[$id]['cost']){
                $obj_norm[$id][$i] = ($max - $v)/$den; //here we can add a displacement/offset to avoid getting zero scores
            } else {
                $obj_norm[$id][$i] = ($v - $min)/$den;
            }
        }
    }

    // objective weights normalized
    $total_obj_w = 0; foreach($active_objective as $id=>$m) $total_obj_w += ($weights[$id] ?? 0);
    $obj_weight_norm = [];
    foreach($active_objective as $id=>$m) $obj_weight_norm[$id] = ($weights[$id] ?? 0)/max(1,$total_obj_w);

    // objective scores per system
    $objective_scores = array_fill(0,$nSystems,0.0);
    foreach($obj_norm as $id=>$vals){
        $w = $obj_weight_norm[$id] ?? 0;
        for($i=0;$i<$nSystems;$i++) $objective_scores[$i] += $w * $vals[$i];
    }

    // subjective scoring with fallback
    $sub_scores = array_fill(0,$nSystems,0.0);
    $total_sub_w = 0; foreach($active_subjective as $id=>$m) $total_sub_w += ($weights[$id] ?? 0);
    $use_uniform_sub_weights = ($total_sub_w == 0 && count($active_subjective) > 0);
    $subjective_weight_per_metric = [];
    if($use_uniform_sub_weights){
        $uniform_w = 1.0 / count($active_subjective);
        foreach($active_subjective as $id=>$m){
            $subjective_weight_per_metric[$id] = $uniform_w;
            for($i=0;$i<$nSystems;$i++){
                $s = $_SESSION['systems'][$i]['subjective'][$id] ?? 0;
                $sub_scores[$i] += $uniform_w * ($s/9.0);
            }
        }
    } else {
        foreach($active_subjective as $id=>$m){
            $w = ($weights[$id] ?? 0)/max(1,$total_sub_w);
            $subjective_weight_per_metric[$id] = $w;
            for($i=0;$i<$nSystems;$i++){
                $s = $_SESSION['systems'][$i]['subjective'][$id] ?? 0;
                $sub_scores[$i] += $w * ($s/9.0);
            }
        }
    }

    // metric contributions
    $metric_contribs = [];
    foreach($active_objective as $id=>$m){
        $metric_contribs[$id] = [];
        $w = $obj_weight_norm[$id] ?? 0;
        for($i=0;$i<$nSystems;$i++){
            $metric_contribs[$id][$i] = ($obj_norm[$id][$i] ?? 0) * $w;
        }
    }
    foreach($active_subjective as $id=>$m){
        $metric_contribs[$id] = [];
        $w = $subjective_weight_per_metric[$id] ?? 0;
        for($i=0;$i<$nSystems;$i++){
            $s = $_SESSION['systems'][$i]['subjective'][$id] ?? 0;
            $metric_contribs[$id][$i] = ($s/9.0) * $w;
        }
    }

    // QA aggregation
    $qa_scores_per_system = [];
    foreach($framework as $qa=>$list){
        for($i=0;$i<$nSystems;$i++) $qa_scores_per_system[$qa][$i]=0.0;
        foreach($list as $m){
            $id = $m['id'];
            if(isset($metric_contribs[$id])){
                for($i=0;$i<$nSystems;$i++){
                    $qa_scores_per_system[$qa][$i] += $metric_contribs[$id][$i];
                }
            }
        }
    }

    // final TW with QA weights
    $present_qas = array_keys($framework);
    $qa_w_final = [];
    $qa_total = 0; foreach($present_qas as $q) $qa_total += ($qa_weights[$q] ?? 1);
    foreach($present_qas as $q) $qa_w_final[$q] = ($qa_weights[$q] ?? 1)/max(1,$qa_total);

    $tw = array_fill(0,$nSystems,0.0);
    foreach($present_qas as $q){
        for($i=0;$i<$nSystems;$i++){
            $tw[$i] += $qa_w_final[$q] * ($qa_scores_per_system[$q][$i] ?? 0);
        }
    }

    return [
        'objective_scores'=>$objective_scores,
        'subjective_scores'=>$sub_scores,
        'qa_scores'=>$qa_scores_per_system,
        'tw'=>$tw,
        'obj_norm'=>$obj_norm,
        'obj_weight_norm'=>$obj_weight_norm,
        'active_obj'=>$active_objective,
        'active_sub'=>$active_subjective,
        'qa_w_final'=>$qa_w_final,
        'used_uniform_sub_weights'=>$use_uniform_sub_weights,
        'metric_contribs'=>$metric_contribs,
    ];
}

// compute results
$results = compute_scores($metrics,$framework);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Intelligent IoT Systems Trustworthiness Assessment (with export)</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2"></script>
  <style>
    .slider-value { min-width: 36px; display:inline-block; text-align:center; }
    .metric-card { margin-bottom:12px; }
    .metric-label { font-weight:600; }
    .table-wrap { max-height:380px; overflow:auto; }
  </style>
</head>
<body class="bg-light">
<div class="container py-4">
  <?php
  $flash = get_flash();
  if($flash){
      $type = in_array($flash['type'], ['success','danger','warning','info']) ? $flash['type'] : 'info';
      echo '<div class="alert alert-'.$type.' alert-dismissible fade show" role="alert">';
      echo htmlspecialchars($flash['msg']);
      echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
      echo '</div>';
  }
  if($results['used_uniform_sub_weights']){
      echo '<div class="alert alert-warning">Note: subjective metric importance weights sum to 0 — using uniform subjective weights as fallback.</div>';
  }
  ?>

  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2>Trustworthiness Assessment Framework for Intelligent IoT Systems</h2>
    <div>
      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="clear_all">
        <button class="btn btn-outline-danger btn-sm">Reset</button>
      </form>
      <button id="saveExportsBtn" class="btn btn-primary btn-sm ms-2">Export Images and Tables</button>
    </div>
  </div>

  <!-- Steps UI  -->
  <div class="card mb-3"><div class="card-body">
    <h5>Step 1 — Define number of Intelligent IoT systems <!--/ Upload objective CSV --></h5>
    <div class="row">
      <div class="col-md-4">
        <form method="post" class="d-flex" >
          <input type="hidden" name="action" value="set_num_systems">
          <input name="num_systems" type="number" class="form-control me-2" min="1" value="<?php echo count($_SESSION['systems']); ?>">
          <button class="btn btn-primary">Set</button>
        </form>
      </div>
      <div class="col-md-8">
        <form method="post" enctype="multipart/form-data" class="d-flex">
          <input type="hidden" name="action" value="upload_objective_csv">
          <input name="objective_csv" type="file" class="form-control me-2" accept=".csv">
          <button class="btn btn-secondary">Upload CSV (Metric, System1, System2, ... or header metric ids)</button>
        </form>
      </div>
    </div>
    <small class="text-muted">CSV file format: first column 'Metric' then system columns</small>
  </div></div>

  <!-- Steps 2-4 etc -->
  <!-- (Use the same UI code as your current app for weights, subjective, objective manual entry) -->

  <!-- Step 2 -->
  <form method="post"><input type="hidden" name="action" value="save_weights">
    <div class="card mb-3"><div class="card-body">
      <h5>Step 2 — Set importance weights for each metric (0=off, 1-9)</h5>
      <p class="text-muted">Use sliders to set importance. Cost means lower values are better. For benefit metrics, higher values are better. <!--Values are saved in session.--></p>
      <div class="row">
        <?php foreach($framework as $qa=>$list): ?>
          <div class="col-md-6">
            <div class="card metric-card">
              <div class="card-body">
                <h6 class="card-title"><?php echo htmlspecialchars($qa); ?> <small class="text-muted">(QA weight <input type="number" name="qa_w_<?php echo preg_replace('/\s+/','_',$qa); ?>" value="<?php echo htmlspecialchars($_SESSION['qa_weights'][$qa] ?? 1); ?>" style="width:70px; display:inline-block;">)</small></h6>
                <?php foreach($list as $m):
                    $id = $m['id'];
                    $cur = $_SESSION['weights'][$id] ?? 5;
                ?>
                <div class="mb-2">
                  <div class="d-flex justify-content-between">
                    <div class="metric-label"><?php echo htmlspecialchars($m['label']); ?> <small class="text-muted">(<?php echo $m['type']=='o'?'objective':'subjective'; ?> <?php echo $m['cost']?'/ cost':''; ?>)</small></div>
                    <div><span class="slider-value" id="val_<?php echo $id; ?>"><?php echo $cur; ?></span></div>
                  </div>
                  <input type="range" min="0" max="9" value="<?php echo $cur; ?>" class="form-range" name="w_<?php echo $id; ?>" id="w_<?php echo $id; ?>" oninput="document.getElementById('val_<?php echo $id; ?>').innerText=this.value">
                </div>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <button class="btn btn-success" type="submit">Save weights</button>
    </div></div>
  </form>

  <!-- Step 3 -->
  <form method="post"><input type="hidden" name="action" value="save_subjective_scores">
    <div class="card mb-3"><div class="card-body">
      <h5>Step 3 — Enter per-system subjective satisfactions (1-9) & names</h5>
      <p class="text-muted">For subjective metrics only. Use sliders to rate how well each system satisfies the metric (1-9).</p>
      <?php foreach($_SESSION['systems'] as $idx=>$sys): ?>
        <div class="mb-3 p-2 border rounded">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div><strong><?php echo htmlspecialchars($sys['name']); ?></strong></div>
            <div class="w-50">
              <input name="system_name_<?php echo $idx; ?>" class="form-control" value="<?php echo htmlspecialchars($sys['name']); ?>">
            </div>
          </div>
          <div class="row">
            <?php foreach($metrics as $id=>$m): if($m['type']!=='s') continue; $cur = $_SESSION['systems'][$idx]['subjective'][$id] ?? 5; ?>
            <div class="col-md-4 mb-2">
              <label class="form-label"><?php echo htmlspecialchars($m['label']); ?></label>
              <div class="d-flex align-items-center">
                <input type="range" min="0" max="9" value="<?php echo $cur; ?>" name="sub_<?php echo $id; ?>_<?php echo $idx; ?>" class="form-range me-2" oninput="this.nextElementSibling.innerText = this.value">
                <span class="badge bg-secondary"><?php echo $cur; ?></span>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <button class="btn btn-primary" type="submit">Save subjective scores</button>
    </div></div>
  </form>

  <!-- Step 4 -->
  <div class="card mb-3"><div class="card-body">
    <h5>Step 4 — Enter objective measurements manually per system</h5>
    <p class="text-muted">If you did not upload a CSV, you can enter objective metric values here for each system.</p>
    <form method="post">
      <input type="hidden" name="action" value="save_objective_manual">
      <?php foreach($_SESSION['systems'] as $idx=>$sys): ?>
        <div class="mb-3 p-2 border rounded">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <div><strong><?php echo htmlspecialchars($sys['name']); ?></strong></div>
          </div>
          <div class="row">
            <?php foreach($metrics as $id=>$m): if($m['type']!=='o') continue; $cur = $_SESSION['systems'][$idx]['objective'][$id] ?? ''; ?>
            <div class="col-md-3 mb-2">
              <label class="form-label"><?php echo htmlspecialchars($m['label']); ?></label>
              <input type="text" name="obj_<?php echo $id; ?>_<?php echo $idx; ?>" value="<?php echo htmlspecialchars($cur); ?>" class="form-control">
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <button class="btn btn-outline-primary" type="submit">Save objective values</button>
    </form>
  </div></div>

  <?php
  // refresh results after saves
  $results = compute_scores($metrics,$framework);
  $sys_names = array_map(function($s){return $s['name'];}, $_SESSION['systems']);
  $tw = $results['tw'];
  $obj_scores = $results['objective_scores'];
  $sub_scores = $results['subjective_scores'];
  $metric_contribs = $results['metric_contribs'];
  ?>

  <div class="row mb-4">
    <div class="col-md-8">
      <div class="card"><div class="card-body">
        <h5>Final Trustworthiness per System</h5>
        <canvas id="twChart" height="120"></canvas>
      </div></div>
    </div>
    <div class="col-md-4">
      <div class="card mb-3"><div class="card-body">
        <h6>Summary (Top 5)</h6>
        <?php
        $rank = $results['tw']; arsort($rank);
        $i=0; foreach($rank as $idx=>$v){ if($i++>=5) break; ?>
          <div class="d-flex justify-content-between"><div><?php echo htmlspecialchars($_SESSION['systems'][$idx]['name']); ?></div><div><?php echo number_format($v,3); ?></div></div>
        <?php } ?>
      </div></div>
      <div class="card"><div class="card-body">
        <h6>Objective vs Subjective</h6>
        <canvas id="objsubChart" height="200"></canvas>
      </div></div>
    </div>
  </div>

  <!-- QA table -->
  <div class="card mb-4"><div class="card-body">
    <h5>Per-Quality Attribute Scores</h5>
    <div class="table-responsive table-wrap">
      <table id="qaTable" class="table table-sm table-striped">
        <thead><tr><th>Quality Attribute</th><?php foreach($sys_names as $sn) echo '<th>'.htmlspecialchars($sn).'</th>'; ?></tr></thead>
        <tbody>
        <?php foreach($results['qa_scores'] as $qa=>$arr): ?>
          <tr><td><?php echo htmlspecialchars($qa); ?></td><?php foreach($arr as $v) echo '<td>'.number_format($v,3).'</td>'; ?></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div></div>

  <!-- Metric contributions table -->
  <div class="card mb-4"><div class="card-body">
    <h5>Per-Metric Contributions per System</h5>
    <div class="table-responsive table-wrap">
      <table id="metricTable" class="table table-sm table-striped">
        <thead>
          <tr>
            <th>Metric (QA)</th>
            <?php foreach($sys_names as $sn) echo '<th>'.htmlspecialchars($sn).'</th>'; ?>
          </tr>
        </thead>
        <tbody>
          <?php
          foreach($framework as $qa=>$list){
              foreach($list as $m){
                  $id = $m['id'];
                  if(!isset($metric_contribs[$id])) continue;
                  echo '<tr>';
                  echo '<td>'.htmlspecialchars($m['label'].' ('.$qa.')').'</td>';
                  foreach($metric_contribs[$id] as $val) echo '<td>'.number_format($val,3).'</td>';
                  echo '</tr>';
              }
          }
          ?>
        </tbody>
      </table>
    </div>
  </div></div>

  <!-- Metric contributions chart -->
  <div class="card mb-4"><div class="card-body">
    <h5>Metric Contributions Chart</h5>
    <div style="overflow:auto;">
      <canvas id="metricChart" height="500"></canvas>
    </div>
  </div></div>

  <!--<div class="card mb-4"><div class="card-body">
    <h5>Export / Save</h5>
    <p class="text-muted">Use the <strong>Save exports</strong> button above to write PNG images and LaTeX files to the application directory.</p>
    <pre style="max-height:300px;overflow:auto;background:#f8f9fa;padding:12px;border-radius:6px;"><?php echo json_encode(['systems'=>$_SESSION['systems'],'weights'=>$_SESSION['weights'],'qa_weights'=>$_SESSION['qa_weights'],'results'=>$results], JSON_PRETTY_PRINT); ?></pre>
  </div></div> -->

  <footer class="text-muted">Developed by Kayode Adewole</footer>
</div>

<script>

// ================================
// DATA
// ================================
const sysNames = <?php echo json_encode($sys_names); ?>;
const twData = <?php echo json_encode(array_values($tw)); ?>;
const objData = <?php echo json_encode(array_values($obj_scores)); ?>;
const subData = <?php echo json_encode(array_values($sub_scores)); ?>;

const rawMetricContribs = <?php echo json_encode($metric_contribs); ?>;

const metricNameMap = <?php
$map = [];
foreach($metrics as $id=>$m){
    $map[$id] = $m['label'].' ('.$m['qa'].')';
}
echo json_encode($map);
?>;


// ================================
// CHART PLUGIN: SHOW VALUES ON TOP
// ================================
Chart.register({

    id: 'valueLabels',

    afterDatasetsDraw(chart){

        const {ctx} = chart;

        chart.data.datasets.forEach((dataset,datasetIndex)=>{

            const meta = chart.getDatasetMeta(datasetIndex);

            meta.data.forEach((bar,index)=>{

                const value = dataset.data[index];

                if(value === null || value === undefined) return;

                ctx.save();

                ctx.fillStyle = '#000';

                ctx.font = 'bold 11px Arial';

                ctx.textAlign = 'center';

                ctx.fillText(
                    Number(value).toFixed(3),
                    bar.x,
                    bar.y - 8
                );

                ctx.restore();

            });

        });

    }

});


// ================================
// FILTER OUT ZERO-CONTRIBUTION METRICS
// ================================
const filteredMetricIds = [];
const filteredMetricLabels = [];

Object.keys(rawMetricContribs).forEach(mid => {

    const values = rawMetricContribs[mid] || [];

    const hasNonZero =
        values.some(v => Math.abs(parseFloat(v)) > 0.0000001);

    if(hasNonZero){

        filteredMetricIds.push(mid);

        filteredMetricLabels.push(
            metricNameMap[mid]
        );
    }
});


// ================================
// METRIC DATASETS
// ================================
const metricDatasets = [];

for(let si=0; si<sysNames.length; si++){

    const data = filteredMetricIds.map(mid => {

        const arr = rawMetricContribs[mid] || [];

        return (typeof arr[si] !== 'undefined')
            ? arr[si]
            : 0;

    });

    const colors = [
        'rgba(54,162,235,0.6)',
        'rgba(255,99,132,0.6)',
        'rgba(75,192,192,0.6)',
        'rgba(255,159,64,0.6)',
        'rgba(153,102,255,0.6)',
        'rgba(201,203,207,0.6)',
        'rgba(255,205,86,0.6)',
        'rgba(100,181,246,0.6)',
        'rgba(255,138,101,0.6)',
        'rgba(129,199,132,0.6)'
    ];

    metricDatasets.push({
        label: sysNames[si],
        data: data,
        backgroundColor: colors[si % colors.length]
    });

}


// ================================
// TRUSTWORTHINESS CHART
// ================================
const ctx = document.getElementById('twChart');

new Chart(ctx,{

    type:'bar',

    data:{
        labels:sysNames,
        datasets:[
            {
                label:'Trustworthiness',
                data:twData,
                backgroundColor:'rgba(54,162,235,0.6)'
            }
        ]
    },

    options:{

        responsive:true,

        plugins:{
            legend:{
                display:false
            },
            valueLabels:{}
        },

        scales:{
            x:{
                title:{
                    display:true,
                    text:'Intelligent IoT Systems'
                }
            },
            y:{
                title:{
                    display:true,
                    text:'Trustworthiness Score'
                },
                beginAtZero:true
            }
        }
    }

});


// ================================
// OBJECTIVE VS SUBJECTIVE CHART
// ================================
const ctx2 = document.getElementById('objsubChart');

new Chart(ctx2,{

    type:'bar',

    data:{
        labels:sysNames,
        datasets:[
            {
                label:'Objective',
                data:objData,
                backgroundColor:'rgba(54,162,235,0.6)'
            },
            {
                label:'Subjective',
                data:subData,
                backgroundColor:'rgba(255,99,132,0.6)'
            }
        ]
    },

    options:{

        responsive:true,

        plugins:{
            valueLabels:{}
        },

        scales:{
            x:{
                title:{
                    display:true,
                    text:'Intelligent IoT Systems'
                }
            },
            y:{
                title:{
                    display:true,
                    text:'Score'
                },
                beginAtZero:true
            }
        }
    }

});


// ================================
// METRIC CONTRIBUTIONS CHART
// ================================
const ctx3 = document.getElementById('metricChart');

new Chart(ctx3,{

    type:'bar',

    data:{
        labels: filteredMetricLabels,
        datasets: metricDatasets
    },

    options:{

        responsive:true,

        plugins:{
            legend:{
                position:'top'
            },

            valueLabels:false //turn off the values on top for this one since it can get too cluttered
        },

        scales:{
            x:{
                title:{
                    display:true,
                    text:'Metrics (QA)'
                },
                ticks:{
                    autoSkip:false,
                    maxRotation:45,
                    minRotation:45
                }
            },
            y:{
                title:{
                    display:true,
                    text:'Contribution (metric × weight)'
                },
                beginAtZero:true
            }
        },

        interaction:{
            mode:'index',
            intersect:false
        },

        maintainAspectRatio:false

    }

});


// ================================
// EXPORT HELPERS
// ================================
function canvasToWhiteDataURL(canvas){

    const w = canvas.width;
    const h = canvas.height;

    const off = document.createElement('canvas');

    off.width = w;
    off.height = h;

    const ctx = off.getContext('2d');

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0,0,w,h);

    ctx.drawImage(canvas,0,0);

    return off.toDataURL('image/png');
}


function extractTableData(tableId){

    const table = document.getElementById(tableId);

    if(!table){
        return {
            headers:[],
            rows:[]
        };
    }

    const headers =
        Array.from(
            table.querySelectorAll('thead th')
        ).map(th => th.innerText.trim());

    const rows =
        Array.from(
            table.querySelectorAll('tbody tr')
        ).map(tr => {

            return Array.from(
                tr.querySelectorAll('td')
            ).map(td => td.innerText.trim());

        });

    return {
        headers,
        rows
    };
}


async function postExports(payload){

    const url =
        window.location.pathname +
        '?action=save_exports';

    const r = await fetch(url,{
        method:'POST',
        headers:{
            'Content-Type':'application/json'
        },
        body:JSON.stringify(payload)
    });

    return r.json();
}


// ================================
// EXPORT BUTTON
// ================================
document.getElementById('saveExportsBtn')
.addEventListener('click', async function(){

    this.disabled = true;
    this.innerText = 'Saving...';

    try{

        const twDataUrl =
            canvasToWhiteDataURL(
                document.getElementById('twChart')
            );

        const objsubDataUrl =
            canvasToWhiteDataURL(
                document.getElementById('objsubChart')
            );

        const metricDataUrl =
            canvasToWhiteDataURL(
                document.getElementById('metricChart')
            );

        const qaTable =
            extractTableData('qaTable');

        const metricTable =
            extractTableData('metricTable');

        const payload = {

            images:{
                tw:twDataUrl,
                objsub:objsubDataUrl,
                metric:metricDataUrl
            },

            qa_table:qaTable,

            metric_table:metricTable
        };

        const res =
            await postExports(payload);

        if(res && res.ok){

            alert(
                'Exports saved successfully.'
            );

        }else{

            alert(
                'Save failed: ' +
                (res.msg || JSON.stringify(res))
            );
        }

    }catch(e){

        alert(
            'Error: ' + e.message
        );

    }finally{

        this.disabled = false;

        this.innerText =
            'Export Images and Tables';
    }

});


// ================================
// AUTO CLOSE ALERTS
// ================================
setTimeout(()=>{

    let a =
        document.querySelector('.alert');

    if(a){

        let bs =
            bootstrap.Alert
            .getOrCreateInstance(a);

        bs.close();
    }

},4000);

</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>