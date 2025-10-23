<?php
// public/index.php — UI/Exibição
session_start();

// UTF-8 sempre
header('Content-Type: text/html; charset=UTF-8');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

require __DIR__.'/../app/helpers.php';
$cfg = require __DIR__.'/../app/config.php';

$error  = null;
$logged = true;   // <- força logado (troque pela sua lógica de auth real)
$data   = get_data(false);
if(function_exists('normalize_dataset')){
  $data = normalize_dataset($data);
}

// Ações básicas
$action = $_POST['action'] ?? $_GET['action'] ?? null;

// Sync (usa flag $logged ao invés de $_SESSION['u'])
if ($logged && $action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  get_data(true);
  header('Location: ./?synced=1');
  exit;
}

// -------- helpers de exibição --------
if (!function_exists('mb_str_contains')) {
  function mb_str_contains($haystack, $needle, $encoding = null) {
    return mb_stripos($haystack, $needle, 0, $encoding ?? 'UTF-8') !== false;
  }
}
function human_ago($ts){
  if(!$ts) return '-';
  $d = time() - (int)$ts;
  if($d < 60)   return $d.'s atrás';
  if($d < 3600) return floor($d/60).' min atrás';
  if($d < 86400)return floor($d/3600).' h atrás';
  return date('d/m H:i', $ts);
}


// próxima data de vencimento (não paga) de um curso
function next_due_for_course($entityName, $courseName, $installments){
  $min = null;
  foreach($installments as $pi){
    if (($pi['entity'] ?? '') === $entityName &&
        ($pi['course'] ?? '') === $courseName &&
        ($pi['status'] ?? '') !== 'paid') {
      $ts = strtotime($pi['due_date'] ?? '');
      if ($ts && ($min === null || $ts < $min)) $min = $ts;
    }
  }
  return $min; // timestamp ou null
}

function course_financials(array $item): array{
  $value    = isset($item['value']) ? (float)$item['value'] : 0.0;
  $received = isset($item['received']) ? (float)$item['received'] : 0.0;
  $pending  = isset($item['pending']) ? (float)$item['pending'] : 0.0;

  if($pending < 0) $pending = 0.0;

  if($value <= 0 && ($received > 0 || $pending > 0)){
    $value = $received + $pending;
  }
  if($value > 0 && $received > $value){
    $value = $received;
  }

  $pending = max(0.0, $value - $received);

  return [round($value, 2), round($received, 2), round($pending, 2)];
}

function entity_financials(array $items): array{
  $total = 0.0;
  $received = 0.0;
  foreach($items as $item){
    [$courseTotal, $courseReceived] = course_financials($item);
    $total    += $courseTotal;
    $received += $courseReceived;
  }
  $pending = max(0.0, $total - $received);
  return [round($total, 2), round($received, 2), round($pending, 2)];
}

$ultimaSync = $logged ? human_ago($data['created_at'] ?? null) : '-';

// -------- filtros (chips) --------
$__viewParam = $_GET['view'] ?? 'list';
$__allowedViews = ['list','grid','carousel'];
$view = in_array($__viewParam, $__allowedViews, true) ? $__viewParam : 'list';

$Q = [
  'q'         => trim($_GET['q'] ?? ''),
  'status'    => $_GET['status'] ?? 'all',   // all|pending|overdue|paid
  'month'     => trim($_GET['month'] ?? ''), // YYYY-MM
  'due_in'    => (int)($_GET['due_in'] ?? 7),// 7|15|30
  'mode'      => $_GET['mode'] ?? 'due',     // 'due' | 'period'
];
$__allowedModes = ['due','period'];
if (!in_array($Q['mode'], $__allowedModes, true)) $Q['mode'] = 'due';

function qstr($overrides=[]){
  $params = $_GET;
  foreach($overrides as $k=>$v){
    if($v===null) unset($params[$k]); else $params[$k]=$v;
  }
  return http_build_query($params);
}

$MONTH_NAMES = [
  1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
  7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'
];

/**
 * Verifica se um mês (YYYY-MM) se sobrepõe ao período do curso (date_start/date_end).
 * Retorna true se houver interseção.
 */
function month_overlaps_course(?string $ym, ?string $startIso, ?string $endIso): bool {
  if (!$ym) return true;                         // sem mês -> não filtra
  if (!$startIso && !$endIso) return false;      // sem datas -> fora em "period"
  [$y,$m] = array_map('intval', explode('-', $ym));
  $monthStart = sprintf('%04d-%02d-01', $y, $m);
  $monthEnd   = date('Y-m-t', strtotime($monthStart)); // último dia do mês

  $aStart = $startIso ?: $endIso;
  $aEnd   = $endIso   ?: $startIso;

  return !($aEnd < $monthStart || $aStart > $monthEnd);
}


function month_human($ym, $names){
  if(!$ym || strpos($ym, '-')===false) return $ym;
  [$y,$m] = explode('-',$ym);
  $m = (int)$m;
  $label = $names[$m] ?? $m;
  return $label.'-'.$y;
}

function status_label($status, $uppercase=false){
  static $map = [
    'pending' => 'Pendente',
    'overdue' => 'Vencido',
    'paid'    => 'Pago',
  ];
  $key = is_string($status) ? mb_strtolower($status, 'UTF-8') : '';
  $label = $map[$key] ?? (string)$status;
  if($uppercase){
    return mb_strtoupper($label, 'UTF-8');
  }
  return $label;
}

// últimos 6 meses para chips de mês
$lastMonths = [];
if ($logged){
  $now = new DateTime('first day of this month');
  $yearStart = new DateTime($now->format('Y').'-01-01');
  $cursor = clone $now;
  while ($cursor >= $yearStart && count($lastMonths) < 6){
    $lastMonths[] = $cursor->format('Y-m');
    $cursor->modify('-1 month');
  }
  $lastMonths = array_reverse($lastMonths);
}

// aplica filtros na lista de parcelas (para os drawers)
$filteredInstallments = [];
if ($logged){
  foreach($data['installments'] as $i){
    $ok = true;
    if($Q['status']!=='all' && ($i['status'] ?? '')!==$Q['status']) $ok=false;
    if($Q['month'] && substr($i['due_date'] ?? '',0,7)!==$Q['month']) $ok=false;
    if($Q['q']){
      $hay = mb_strtolower(($i['entity'] ?? '').' '.($i['course'] ?? ''), 'UTF-8');
      if(!mb_str_contains($hay, mb_strtolower($Q['q'],'UTF-8'))) $ok=false;
    }
    if($ok) $filteredInstallments[] = $i;
  }
}

// Export CSV (respeita filtros atuais dos drawers)
if ($logged && isset($_GET['export']) && $_GET['export']==='csv') {
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="parcelas_'.date('Ymd_His').'.csv"');
  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM
  fputcsv($out, ['Entidade','Curso','Vencimento','Valor','Status'], ';');
  foreach($filteredInstallments as $i){
    fputcsv($out, [
      $i['entity'] ?? '',
      $i['course'] ?? '',
      dmy($i['due_date'] ?? ''),
      number_format((float)($i['amount'] ?? 0), 2, ',', '.'),
      status_label($i['status'] ?? '')
    ], ';');
  }
  fclose($out);
  exit;
}

// AGRUPA ENTIDADES p/ lista visual (respeita busca/status/mês)
$groupFiltered=[];
if($logged && !empty($data['entities'])){
  // DESC: só remove "-" se houver ao menos uma entidade real
  $hasReal = false;
  foreach($data['entities'] as $e){
    if(isset($e['name']) && trim($e['name'])!=='-'){ $hasReal = true; break; }
  }

  foreach($data['entities'] as $e){
    if($hasReal && isset($e['name']) && trim($e['name'])==='-') continue; // remove "-" apenas se existir outra entidade
    $bucket=['name'=>$e['name'] ?? '-', 'items'=>[], 'total'=>0.0, 'received'=>0.0, 'pending'=>0.0];

    foreach($e['items'] as $it){
      // busca textual
      $matchesQ = $Q['q'] ? (mb_stripos(mb_strtolower(($e['name'] ?? '').' '.($it['course'] ?? ''),'UTF-8'), mb_strtolower($Q['q'],'UTF-8'))!==false) : true;
      if(!$matchesQ) continue;

  if ($Q['mode']==='due') {
  // === MODO VENCIMENTO (filtra diretamente em $data['installments']) ===
  $exists = false;
  foreach ($data['installments'] as $pi) {
    if (($pi['entity'] ?? '-') === ($it['entity'] ?? '-') && ($pi['course'] ?? '-') === ($it['course'] ?? '-')) {
      // status (se selecionado)
      if ($Q['status'] !== 'all' && ($pi['status'] ?? '') !== $Q['status']) {
        continue;
      }
      // mês (se selecionado)
      if ($Q['month'] !== '' && substr($pi['due_date'] ?? '', 0, 7) !== $Q['month']) {
        continue;
      }
      $exists = true;
      break;
    }
  }
  if (!$exists) continue;
} else {
  // === MODO PERÍODO DO CURSO ===

  // 1) se há month, exige sobreposição do mês com [date_start..date_end]
  $startIso = $it['date_start'] ?? null;
  $endIso   = $it['date_end'] ?? null;
  if ($Q['month'] !== '') {
    if (!month_overlaps_course($Q['month'], $startIso, $endIso)) {
      continue; // não cruza com o mês selecionado
    }
  }

  // 2) se há status, exige que exista ao menos UMA parcela com esse status
  if ($Q['status'] !== 'all') {
    $hasStatus = false;
    foreach ($data['installments'] as $pi) {
      if (
        ($pi['entity'] ?? '') === ($it['entity'] ?? '') &&
        ($pi['course'] ?? '') === ($it['course'] ?? '') &&
        ($pi['status'] ?? '') === $Q['status']
      ) {
        $hasStatus = true;
        break;
      }
    }
    if (!$hasStatus) continue;
  }
}


      // 2) se há status, exige que exista parcela desse status (sem filtrar mês)
if ($Q['status'] !== 'all') {
  $hasStatus = false;
  foreach ($data['installments'] as $pi) {
    if (
      ($pi['entity'] ?? '') === ($it['entity'] ?? '') &&
      ($pi['course'] ?? '') === ($it['course'] ?? '') &&
      ($pi['status'] ?? '') === $Q['status']
    ) { $hasStatus = true; break; }
  }
  if (!$hasStatus) continue;
}


      [$courseTotal, $courseReceived, $coursePending] = course_financials($it);
      $normalizedItem = $it;
      $normalizedItem['value']    = $courseTotal;
      $normalizedItem['received'] = $courseReceived;
      $normalizedItem['pending']  = $coursePending;

      $bucket['items'][]   = $normalizedItem;
      $bucket['total']    += $courseTotal;
      $bucket['received'] += $courseReceived;
    }
    if($bucket['items']){
      $bucket['pending'] = max(0.0, $bucket['total'] - $bucket['received']);
      $groupFiltered[] = $bucket;
    }
  }
}

$FIRST_GROUPS = 9999; // mostra tudo aberto
$visibleGroups = $groupFiltered;
$hiddenGroups  = [];

// flag de filtro aplicado
$hasFilter = ($Q['status']!=='all' || $Q['month']!=='' || $Q['q']!=='');

// ===== KPIs (regra nova: vem prontos do helpers) =====
$kpis = $data['kpis'] ?? ['receivable'=>0,'received'=>0,'overdue'=>0];
$receivable = (float)$kpis['receivable'];
$received   = (float)$kpis['received'];
$overdue    = (float)$kpis['overdue'];
$base=max(1,$receivable+$received);
$pctRec=min(100,round($receivable/$base*100));
$pctRcvd=min(100,round($received/$base*100));
$pctOvd=$base>0?min(100,round($overdue/$base*100)):0;

?>
<!doctype html>
<html lang="pt-br" class="theme-light">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title><?= htmlspecialchars($cfg['APP_NAME'] ?? 'App', ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="./assets/style.css">

  <!-- HOTFIXES/OVERRIDES (sem tocar no style.css) -->
  <style>
    /* 1) sem rolagem horizontal no drawer */
    .drawer, .drawer__body { overflow-x: hidden !important; }
    .drawer__panel { width: min(720px, 92vw); max-width: min(720px, 92vw); }
    .drawer__panel * { max-width: 100%; }

    /* 2) responsividade */
    .drawer .forecast { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)) !important; }

    /* 3) cursor de clique em tudo que abre subtela */
    .kpi, .info-line, .js-entity, .js-course, .chip--toggle { cursor: pointer; }

    /* 4) animação do drawer */
    .drawer{ transition:opacity .28s ease; }
    .drawer__panel{ transform:translateX(60px) scale(.98); opacity:.98; }
    .drawer--open .drawer__panel{
      animation:ios-in .32s cubic-bezier(.22,.8,.16,1) both;

    }
.progress .bar {
  background: linear-gradient(90deg, #22d3ee, #06b6d4) !important;
  height: 6px !important;
  opacity: 1 !important;
  display: block !important;
}
.progress {
  background: rgba(30,41,59,0.4) !important;
  min-height: 6px !important;
  overflow: visible !important;
}

    
    @keyframes ios-in{
      0%{ transform:translateX(60px) scale(.98); opacity:.0; }
      60%{ transform:translateX(0) scale(1.005); opacity:1; }
      100%{ transform:translateX(0) scale(1); opacity:1; }
      
    }
  </style>
  
</head>
  <body class="theme-light bgfx">


<?php if(!$logged): ?>
 
<?php else: ?>
  <div class="container">
    <!-- HERO -->
    <div class="hero" role="banner">
      <div class="hero__art" aria-hidden="true">
        <span class="hero__orb"></span>
       <img src="./assets/julietaIMG.png" alt="Julieta" class="hero__avatar">
        <span class="hero__halo"></span>
      </div>
      <div class="hero__text">
        <span class="hero__tag">JML - Dashboard Financeiro</span>
        <h1 class="hero__title"><?= htmlspecialchars($cfg['APP_NAME'] ?? 'App', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="hero__subtitle">Curadoria de dados 2025</p>
      </div>
      <div class="hero__actions">
        <button id="btnConfig" class="btn hero__btn" type="button">&#9881; Configurar</button>
        <form method="post" class="hero__form">
          <input type="hidden" name="action" value="sync">
          <button class="btn btn--primary hero__btn" title="Última sincronização: <?= htmlspecialchars($ultimaSync, ENT_QUOTES, 'UTF-8') ?>">Sincronizar agora</button>
        </form>
      </div>
    </div>

    <!-- KPIs (regra nova) -->
    <div class="grid kpis">
      <div class="card kpi kpi--rec" data-open="pending" tabindex="0" role="button" aria-label="Abrir detalhes: A Receber" onclick="window.dashboardOpenStatus && window.dashboardOpenStatus('pending')">
        <h4>A Receber<?= $hasFilter ? ' (visão geral)' : '' ?></h4>
        <div class="amount"><?= brl($receivable) ?></div>
        <div class="progress"><div class="bar bar--rec" style="width:<?= $pctRec ?>%"></div></div>
        <div class="sub">Vencidos + A vencer</div>
      </div>
      <div class="card kpi kpi--rcv" data-open="paid" tabindex="0" role="button" aria-label="Abrir detalhes: Recebidos" onclick="window.dashboardOpenStatus && window.dashboardOpenStatus('paid')">
        <h4>Recebidos<?= $hasFilter ? ' (visão geral)' : '' ?></h4>
        <div class="amount amount--ok"><?= brl($received) ?></div>
        <div class="progress"><div class="bar bar--rcv" style="width:<?= $pctRcvd ?>%"></div></div>
        <div class="sub">Total de Honorários + Pró-labore + Repasses</div>
      </div>
      <div class="card kpi kpi--ovd" data-open="overdue" tabindex="0" role="button" aria-label="Abrir detalhes: Vencidos" onclick="window.dashboardOpenStatus && window.dashboardOpenStatus('overdue')">
        <h4>Vencidos<?= $hasFilter ? ' (visão geral)' : '' ?></h4>
        <div class="amount amount--bad"><?= brl($overdue) ?></div>
        <div class="progress"><div class="bar bar--ovd" style="width:<?= $pctOvd ?>%"></div></div>
        <div class="sub">Curso ministrado há mais de 30 dias — honorários vencidos</span></div>
      </div>
    </div>


    <!-- Vencendo em X dias -->
    <?php
      $validDueIn = in_array($Q['due_in'], [7,15,30], true) ? $Q['due_in'] : 7;
    ?>
    <div class="section-title section-title--row">
      <span>Vencendo em <?= $validDueIn ?> dias</span>
    </div>
    <div class="card card--filter-panel">
      <div class="filters__group filters__group--standalone">
        <span class="filters__label">Prazo</span>
        <div class="chips-line">
          <?php foreach([7,15,30] as $di):
            $active = $validDueIn===$di ? 'is-active' : '';
            $href = '?'.qstr(['due_in'=>$di]);
          ?>
            <a class="chip chip--toggle <?= $active ?>" href="<?= $href ?>"><?= $di ?> dias</a>
          <?php endforeach; ?>
        </div>
      </div>
      <?php
        $limitDate = strtotime('+'.$validDueIn.' days', strtotime('today'));
        $upcoming = array_values(array_filter(
          $filteredInstallments,
          fn($i) => ($i['status']??'')!=='paid' &&
                    strtotime($i['due_date']??'2099-12-31') <= $limitDate &&
                    strtotime($i['due_date']??'1970-01-01') >= strtotime('today')
        ));
        usort($upcoming,fn($a,$b)=>strcmp(($a['due_date']??''),($b['due_date']??'')));
      ?>
      <?php if(!$upcoming): ?>
        <div class="alert">Nada por enquanto.</div>
      <?php else: ?>
        <div class="list">
          <?php foreach($upcoming as $u): ?>
            <div class="item" data-tip="<?= htmlspecialchars(($u['entity']??'').' - '.($u['course']??'').' - Venc.: '.dmy($u['due_date']??'').' - '.brl((float)($u['amount']??0)).' - '.status_label($u['status']??'', true), ENT_QUOTES, 'UTF-8') ?>">
              <div class="top">
                <div class="entity"><?= htmlspecialchars($u['entity'] ?? '', ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($u['course'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="chips">
                  <span class="chip">Venc.: <?= dmy($u['due_date'] ?? '') ?></span>
                  <span class="chip mono"><?= brl((float)($u['amount'] ?? 0)) ?></span>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>


    <!-- LISTA DE ENTIDADES -->
    <div class="section-title section-title--row">
      <span>Entidades <?= $hasFilter ? '<small class="chip">filtrado</small>' : '' ?></span>
      <div class="filters__group">
        <span class="filters__label">Exibição</span>
        <div class="chips-line">
          <?php
            $tabs = ['list'=>'Lista','grid'=>'Grade','carousel'=>'Carrossel'];
            foreach($tabs as $k=>$lbl):
              $active = $view===$k ? 'is-active' : '';
          ?>
            <a class="chip chip--toggle <?= $active ?>" href="?<?= qstr(['view'=>$k]) ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- FILTERS -->
    <div class="filters filters--list">

<!-- MODO -->
<div class="filters__group">
  <span class="filters__label">Modo</span>
  <div class="chips-line">
    <?php
      $modes = ['due'=>'Vencimento', 'period'=>'Período do curso'];
      foreach($modes as $k=>$lbl):
        $active = $Q['mode']===$k ? 'is-active' : '';
        $href = '?'.qstr(['mode'=>$k]);
    ?>
      <a class="chip chip--toggle <?= $active ?>" href="<?= $href ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>


      <!-- STATUS simples -->
      <div class="filters__group">
        <span class="filters__label">Status</span>
        <div class="chips-line">
          <?php
            $statuses=['all'=>'Todos','pending'=>'Pendentes','overdue'=>'Vencidos','paid'=>'Pagos'];
            foreach($statuses as $k=>$lbl):
              $active = $Q['status']===$k ? 'is-active' : '';
              $href = '?'.qstr(['status'=>$k ?: null]);
          ?>
            <a class="chip chip--toggle <?= $active ?>" href="<?= $href ?>"><?= $lbl ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- MÊS -->
      <div class="filters__group">
       <span class="filters__label">
  <?= $Q['mode']==='period' ? 'Mês do curso' : 'Mês de vencimento' ?>
</span>

        <div class="chips-line filters__chips">
          <?php
            $active = $Q['month']==='' ? 'is-active' : '';
            echo '<a class="chip chip--toggle '.$active.'" href="?'.qstr(['month'=>null]).'">Todos</a>';
            foreach($lastMonths as $m):
              $lbl = month_human($m, $MONTH_NAMES);
              $active = $Q['month']===$m ? 'is-active' : '';
          ?>
            <a class="chip chip--toggle <?= $active ?>" href="?<?= qstr(['month'=>$m]) ?>"><?= str_replace(' ', ' ', $lbl) ?></a>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- BUSCA -->
      <div class="filters__group filters__search">
        <form method="get" class="search">
          <?php if($Q['mode']!=='due'): ?>
          <input type="hidden" name="mode" value="<?= htmlspecialchars($Q['mode'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <?php if($Q['status']!=='all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($Q['status'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <?php if($Q['month']!==''): ?><input type="hidden" name="month" value="<?= htmlspecialchars($Q['month'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <input class="input" type="search" name="q" value="<?= htmlspecialchars($Q['q'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Buscar entidade/curso…">
          <button class="btn" type="submit">Buscar</button>
          <a class="btn" href="./">Limpar</a>
        </form>
      </div>
    </div>

    <!-- VISUAIS -->
  <?php if($view==='grid'): ?>
  <!-- GRADE -->
  <div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:18px">

    <?php foreach($groupFiltered as $e): ?>
      <?php
        [$entTotal, $entReceived, $entPending] = entity_financials($e['items'] ?? []);
        $pct = $entTotal > 0 ? min(100, round(($entReceived / $entTotal) * 100)) : 0;
      ?>

      <div class="card js-entity"
           data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>"
           data-tip="<?= htmlspecialchars('Total '.brl($entTotal).' - Recebido '.brl($entReceived), ENT_QUOTES, 'UTF-8') ?>">
        <div class="entity" style="margin-bottom:8px">
          <?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
        </div>
        <div class="progress"><div class="bar" style="width:<?= $pct ?>%" title="<?= $pct ?>%"></div></div>
        <div class="chips" style="margin-top:10px">
          <span class="chip">Recebido <?= brl($entReceived) ?></span>
          <span class="chip">Falta <?= brl($entPending) ?></span>
          <span class="chip mono">Total <?= brl($entTotal) ?></span>
        </div>

        <div class="list" style="margin-top:12px">
          <?php foreach($e['items'] as $it):
            [$courseTotal, $courseReceived, $coursePending] = course_financials($it);
            $coursePct = $courseTotal > 0 ? min(100, round(($courseReceived / $courseTotal) * 100)) : 0;
$tip = htmlspecialchars(
  ($it['entity'] ?? '-') . ' - ' . ($it['course'] ?? '-') .
  ' - Total ' . brl($courseTotal) .
  ' - Recebidos ' . brl($courseReceived) .
  ' - Falta ' . brl($coursePending),
  ENT_QUOTES,
  'UTF-8'
);

            $chips = [];
            if ($Q['status']!=='all') $chips[] = status_label($Q['status'], true);
            if ($Q['month']!=='') {
              $chips[] = ($Q['mode']==='period' ? 'Período: ' : 'Venc.: ') . month_human($Q['month'],$MONTH_NAMES);
            }

         $nextTs   = next_due_for_course($it['entity'] ?? '-', $it['course'] ?? '-', $data['installments']);

            $nextLabel= $nextTs ? date('d/m/Y', $nextTs) : null;
            $isLongCourse = mb_strlen($it['course'] ?? '', 'UTF-8') > 65;
            $itemClass = 'list-item js-course'.($isLongCourse ? ' list-item--long' : '');
          ?>
           <div class="<?= $itemClass ?>"
     data-entity="<?= htmlspecialchars($it['entity'] ?? '-', ENT_QUOTES, 'UTF-8') ?>"
     data-course="<?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?>"
     
     data-tip="<?= $tip ?>">
     


              <div class="list-item__body">
                <div class="list-item__course">
                  <div class="list-item__meta">
                    <?php if (!empty($it['date_start'])): ?>
                      <span class="pill">data Início: <?= dmy($it['date_start']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($it['date_end'])): ?>
                      <span class="pill">data Fim: <?= dmy($it['date_end']) ?></span>
                    <?php endif; ?>
                    <span class="pill">Carga Horária: <?= htmlspecialchars($it['ch'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                  </div>
                </div>
                <div class="list-item__values">
                  <span class="list-item__total"><?= brl($courseTotal) ?></span>
                  <?php if($chips): ?>
                    <div class="list-item__tags chips" style="justify-content:flex-end">
                      <?php foreach($chips as $c): ?>
                        <span class="chip"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></span>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="progress progress--item"><div class="bar" style="width:<?= $coursePct ?>%" title="<?= $coursePct ?>%"></div></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if(!$groupFiltered): ?>
      <div class="alert">Nenhum resultado para os filtros atuais.</div>
    <?php endif; ?>
  </div>

    <?php elseif($view==='carousel'): ?>
      <!-- CARROSSEL -->
      <div class="card" style="overflow:hidden">
        <div style="display:flex; gap:16px; overflow:auto; scroll-snap-type:x mandatory; padding-bottom:6px">
          <?php foreach($groupFiltered as $e): ?>
            <?php [$entTotal, $entReceived, $entPending] = entity_financials($e['items'] ?? []); $pct=$entTotal>0?min(100,round($entReceived/$entTotal*100)):0; ?>
            <div class="card js-entity" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" style="min-width:340px; scroll-snap-align:start">
              <div class="entity" style="margin-bottom:8px"><?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="progress"><div class="bar" style="width:<?= $pct ?>%" title="<?= $pct ?>%"></div></div>
              <div class="chips" style="margin-top:10px">
                <span class="chip">Recebido <?= brl($entReceived) ?></span>
                <span class="chip">Falta <?= brl($entPending) ?></span>
                <span class="chip mono">Total <?= brl($entTotal) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
          <?php if(!$groupFiltered): ?><div class="alert">Nenhum resultado para os filtros atuais.</div><?php endif; ?>
        </div>
      </div>

    <?php else: ?>
      <!-- LISTA (accordion por entidade – ABERTO) -->
      <div class="acc list">
        <?php foreach($visibleGroups as $e): ?>
          <?php
            [$entTotal, $entReceived, $entPending] = entity_financials($e['items'] ?? []);
            $pct = $entTotal > 0 ? min(100, round(($entReceived / $entTotal) * 100)) : 0;
          ?>
          <details>
            <summary>
              <div class="entity js-entity" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="chips">
                <span class="chip">Recebido <?= brl($entReceived) ?></span>
                <span class="chip">Falta <?= brl($entPending) ?></span>
                <span class="chip mono">Total <?= brl($entTotal) ?></span>
              </div>
            </summary>

            <div class="group-body">
      <div class="progress progress--group">
  <div class="bar" style="width:<?= $pct ?>%" title="<?= $pct ?>%"></div>
</div>
              


              <?php foreach($e['items'] as $it):
                [$courseTotal, $courseReceived, $coursePending] = course_financials($it);
                $coursePct      = $courseTotal > 0 ? min(100, round(($courseReceived / $courseTotal) * 100)) : 0;

                // próxima data não paga
              $nextTs = next_due_for_course($it['entity'] ?? '-', $it['course'] ?? '-', $data['installments']);

                $nextLabel = $nextTs ? date('d/m/Y', $nextTs) : null;

                $tipParts = array_filter([
                  $e['name'] ?? '',
                  $it['course'] ?? '',
                  'Total '.brl($courseTotal),
                  'Recebidos '.brl($courseReceived),
                  'Falta '.brl($coursePending)
                ]);
                $tip = htmlspecialchars(implode(' - ', $tipParts), ENT_QUOTES, 'UTF-8');

                // chips de status/mês (do filtro atual) à direita
                $chips = [];
                if ($Q['status']!=='all') $chips[] = status_label($Q['status'], true);
                if ($Q['month']!=='') {
                  $chips[] = ($Q['mode']==='period' ? 'Período: ' : 'Venc.: ') . month_human($Q['month'],$MONTH_NAMES);
                }

                // labels de datas com texto explícito
                $di = $it['date_start'] ? dmy($it['date_start']) : '—';
                $df = $it['date_end']   ? dmy($it['date_end'])   : '—';
                $metaTxt = "dataInicio: $di · dataFim: $df · CH ".htmlspecialchars($it['ch'] ?? '-', ENT_QUOTES, 'UTF-8');

                 $isLongCourse = mb_strlen($it['course'] ?? '', 'UTF-8') > 65;
                 $itemClass = 'list-item js-course'.($isLongCourse ? ' list-item--long' : '');
              ?>
               <div class="<?= $itemClass ?>"
     data-entity="<?= htmlspecialchars($it['entity'] ?? '-', ENT_QUOTES, 'UTF-8') ?>"
     data-course="<?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?>"
     data-tip="<?= $tip ?>">

                  <div class="list-item__body">
                    <div class="list-item__course">
                     <div class="list-item__meta">
  <?php if (!empty($it['date_start'])): ?>
    <span class="pill">Data início: <?= dmy($it['date_start']) ?></span>
  <?php endif; ?>

  <?php if (!empty($it['date_end'])): ?>
    <span class="pill">Data fim: <?= dmy($it['date_end']) ?></span>
  <?php endif; ?>

  <span class="pill">Carga Horária: <?= htmlspecialchars($it['ch'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
</div>

                      <strong class="list-item__title"><?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="list-item__values">
                      <span class="list-item__total"><?= brl($courseTotal) ?></span>
                      <span class="list-item__split">
                        <?= brl($courseReceived) ?> recebidos - <?= brl($coursePending) ?> a receber
                        <?php if($nextLabel): ?> · Próx. venc.: <strong><?= htmlspecialchars($nextLabel, ENT_QUOTES, 'UTF-8') ?></strong><?php endif; ?>
                      </span>
                      <?php if($chips): ?>
                        <div class="list-item__tags chips" style="justify-content:flex-end">
                          <?php foreach($chips as $c): ?><span class="chip"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="progress progress--item"><div class="bar" style="width:<?= $coursePct ?>%" title="<?= $coursePct ?>%"></div></div>
                </div>
              <?php endforeach; ?>
            </div>
          </details>
        <?php endforeach; ?>

        <?php if(!$groupFiltered): ?><div class="alert">Nenhum resultado para os filtros atuais.</div><?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="footer" style="margin:22px 0;color:var(--muted-use)">feito com ❤ em PHP puro</div>
  </div>

  <!-- back to top -->
  <button id="backtop" class="btn backtop" title="Topo" type="button">Topo &#8593;</button>

  <!-- CONFIG MODAL -->
  <div id="modal" class="modal" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Configurações">
    <div class="modal__panel">
      <div class="modal__head">
        <div class="title">Configurações</div>
        <button id="modalClose" class="btn" type="button" title="Fechar">✕</button>
      </div>
      <div class="modal__body">
        <div class="config-card">
          <h4>Tema</h4>
          <div class="theme-toggle" data-active="light" role="radiogroup" aria-label="Tema de cor">
            <label class="theme-toggle__option">
              <input type="radio" name="theme" value="dark">
              <span class="theme-toggle__label">
                <span aria-hidden="true" class="theme-toggle__icon">☾</span>
                <span>Dark</span>
              </span>
            </label>
            <label class="theme-toggle__option">
              <input type="radio" name="theme" value="light">
              <span class="theme-toggle__label">
                <span aria-hidden="true" class="theme-toggle__icon">☀</span>
                <span>Light</span>
              </span>
            </label>
            <span class="theme-toggle__indicator" aria-hidden="true"></span>
          </div>
        </div>

        <div class="config-card">
          <h4>Cor de destaque</h4>
          <div class="hue">
            <div class="hue-picker" id="huePicker">
              <input id="hue" type="range" min="0" max="360" step="1" aria-label="Selecionar matiz">
              <span class="hue-picker__thumb" id="hueThumb">
                <span class="hue-picker__value" id="hueValue">160°</span>
              </span>
            </div>
            <div class="hue__swatches">
              <?php foreach([145,200,260,300,20,60,100] as $h): ?>
                <button type="button" class="swatch" data-h="<?= (int)$h ?>" style="--swatch-h:<?= (int)$h ?>"></button>
              <?php endforeach; ?>
              <div id="hueNow" class="swatch swatch--current" title="Atual"></div>
            </div>
          </div>
        </div>

        <div class="sub config-card__foot">Última sync: <?= htmlspecialchars($ultimaSync, ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
  </div>

  <!-- DRAWER -->
  <div id="drawer" class="drawer" aria-hidden="true">
    <div class="drawer__panel" role="dialog" aria-modal="true" aria-label="Detalhes">
      <div class="drawer__head">
        <button id="drawerBack" class="btn" type="button" style="display:none">← Voltar</button>
        <div class="title" id="drawerTitle">Detalhes</div>
        <button id="drawerClose" class="btn" type="button" title="Fechar">✕</button>
      </div>
      <div class="drawer__body" id="drawerBody"></div>
    </div>
  </div>

  <!-- datasets -->
  <script id="dataset" type="application/json"><?= json_encode(['all'=>$data['installments'] ?? []], JSON_UNESCAPED_UNICODE) ?></script>
  <script id="entitiesDataset" type="application/json"><?= json_encode($groupFiltered, JSON_UNESCAPED_UNICODE) ?></script>
  <script id="filteredInstallments" type="application/json"><?= json_encode($filteredInstallments, JSON_UNESCAPED_UNICODE) ?></script>

<?php endif; ?>

<script>
(function(){
  "use strict";
  var root=document.documentElement, body=document.body;

  // datasets
  var datasetAll=[], filteredInstallments=[], entityDataset=[], entityMap={};
  var STATUS_LABELS = { pending: 'PENDENTE', overdue: 'VENCIDO', paid: 'PAGO' };
  function statusLabel(value){
    var key = typeof value === 'string' ? value.toLowerCase() : '';
    return STATUS_LABELS[key] || String(value || '').toUpperCase();
  }
  function safeParseJSON(elId){
    var el=document.getElementById(elId);
    if(!el||!el.textContent) return null;
    try{ return JSON.parse(el.textContent); }catch(e){ return null; }
  }
  var parsedAll = safeParseJSON('dataset');
  if(parsedAll){
    if(Array.isArray(parsedAll.all)) datasetAll = parsedAll.all.slice();
    else if(Array.isArray(parsedAll)) datasetAll = parsedAll.slice();
    else if(parsedAll.all) datasetAll = [].concat(parsedAll.all);
  }
  filteredInstallments = safeParseJSON('filteredInstallments') || [];
  entityDataset = safeParseJSON('entitiesDataset') || [];

  if(!Array.isArray(datasetAll)) datasetAll=[];
  if(!Array.isArray(filteredInstallments)) filteredInstallments=[];
  if(!Array.isArray(entityDataset)) entityDataset=[];

  entityDataset.forEach(function(ent){
    if(ent && ent.name && !entityMap[ent.name]){
      entityMap[ent.name] = ent;
    }
  });
  function findEntity(name){ return entityMap[name] || null; }

function collectEntityDetail(name){
  var ent = findEntity(name); // 'name' aqui é a classificação
  var pairs = new Set();
  if (ent && Array.isArray(ent.items)) {
    ent.items.forEach(function(it){
      pairs.add(String(it.entity||'-') + '|' + String(it.course||'-'));
    });
  }
  var rel = (datasetAll||[]).filter(function(i){
    return pairs.has(String(i.entity||'-') + '|' + String(i.course||'-'));
  });
  return { entity: ent, name: name, installments: rel };
}
  function collectCourseDetail(entityName, courseName){
  // procura meta do curso percorrendo todas as classificações
  var courseInfo = null;
  for (var k = 0; k < entityDataset.length; k++) {
    var ent = entityDataset[k];
    if (ent && Array.isArray(ent.items)) {
      for (var i = 0; i < ent.items.length; i++) {
        var itm = ent.items[i];
        if ((String(itm.entity||'-') === String(entityName||'-')) &&
            (String(itm.course||'-') === String(courseName||'-'))) {
          courseInfo = itm;
          break;
        }
      }
      if (courseInfo) break;
    }
  }
  // parcelas relacionadas (já estava ok)
  var rel = (datasetAll||[]).filter(function(item){
    return (item.entity||'') === entityName && (item.course||'') === courseName;
  });
  return { entityName: entityName, courseName: courseName, course: courseInfo, installments: rel };
}


  // === Tema persistente ===
  var themeToggleEl = document.querySelector('.theme-toggle');
  var huePickerEl   = document.getElementById('huePicker');
  var hueThumbEl    = document.getElementById('hueThumb');
  var hueValueEl    = document.getElementById('hueValue');
  var hueCurrentEl  = document.getElementById('hueNow');
  var hueInp        = document.getElementById('hue');

  function updateThemeToggle(theme){
    if(!themeToggleEl) return;
    themeToggleEl.dataset.active = (theme === 'dark') ? 'dark' : 'light';
  }

  function updateHueUI(h){
    if(!huePickerEl) return;
    var hue = Math.max(0, Math.min(360, Number(h) || 0));
    var pct = hue / 360 * 100;
    huePickerEl.style.setProperty('--pos', pct + '%');
    huePickerEl.style.setProperty('--hue', hue);
    if(hueValueEl) hueValueEl.textContent = Math.round(hue) + '°';
    if(hueThumbEl) hueThumbEl.setAttribute('aria-label', 'Matiz ' + Math.round(hue));
    if(hueCurrentEl) hueCurrentEl.style.background = 'hsl(' + hue + ' 85% 55%)';
  }

  function setTheme(t){
    if(!t) t='dark';
    if(t!=='dark' && t!=='light'){ t='dark'; try{ localStorage.setItem('julieta:theme','dark'); }catch(e){} }
    ['theme-dark','theme-light'].forEach(function(c){ root.classList.remove(c); body.classList.remove(c); });
    var cn = 'theme-'+t;
    root.classList.add(cn); body.classList.add(cn);
    root.dataset.theme = t; body.dataset.theme = t;
    try{ localStorage.setItem('julieta:theme', t); }catch(e){}
    // marca radio
    try{
      var radios=document.querySelectorAll('input[name="theme"]');
      Array.prototype.forEach.call(radios, function(r){ r.checked = (r.value===t); });
    }catch(e){}
    updateThemeToggle(t);
  }
  function setHue(h){
    var hueNum = Math.max(0, Math.min(360, Number(h)));
    if(isNaN(hueNum)) hueNum = 145;
    var hueStr = String(hueNum);
    root.style.setProperty('--accent-h', hueStr);
    try{ localStorage.setItem('julieta:hue', hueStr);}catch(e){}
    if(hueInp) hueInp.value=hueStr;
    updateHueUI(hueStr);
  }
  try{ setTheme((localStorage.getItem('julieta:theme')||'dark')); }catch(e){ setTheme('dark'); }
  try{
    var storedHue = localStorage.getItem('julieta:hue');
    if(!storedHue || isNaN(+storedHue)) storedHue='145';
    setHue(storedHue);
  }catch(e){ setHue('145'); }

  // bind radios do tema (FIX do toggle que não mudava)
  document.addEventListener('change', function(e){
    var t = e.target;
    if(t && t.name==='theme' && (t.value==='dark' || t.value==='light')){
      setTheme(t.value);
    }
  });

  // modal simples (config)
  var modal=document.getElementById('modal');
  var openB=document.getElementById('btnConfig');
  var closeB=document.getElementById('modalClose');
  var modalTimer=null;
  var MODAL_ANIM_MS = 380;
  function openModal(){
    if(!modal) return;
    if(modalTimer){ clearTimeout(modalTimer); modalTimer=null; }
    modal.classList.remove('modal--closing');
    modal.classList.add('modal--open');
    modal.setAttribute('aria-hidden','false');
    body.classList.add('no-scroll');
  }
  function closeModal(){
    if(!modal || !modal.classList.contains('modal--open')) return;
    modal.classList.add('modal--closing');
    modal.setAttribute('aria-hidden','true');
    body.classList.remove('no-scroll');
    if(modalTimer){ clearTimeout(modalTimer); }
    modalTimer = setTimeout(function(){
      modal.classList.remove('modal--open');
      modal.classList.remove('modal--closing');
      modalTimer = null;
    }, MODAL_ANIM_MS);
  }
  if(modal && !modal.hasAttribute('aria-hidden')) modal.setAttribute('aria-hidden','true');
  if(openB) openB.addEventListener('click', openModal);
  if(closeB) closeB.addEventListener('click', closeModal);
  if(modal){ modal.addEventListener('click', function(e){ if(e.target===modal){ closeModal(); } }); }

  // hue
  if(hueInp) hueInp.addEventListener('input', function(e){ setHue(e.target.value); });
  var sw = document.querySelectorAll('.swatch[data-h]');
  for(var i=0;i<sw.length;i++){ sw[i].addEventListener('click', function(){ setHue(this.getAttribute('data-h')); }); }

  // back-to-top
  var back=document.getElementById('backtop');
  function onScroll(){ if(back) back.classList.toggle('backtop--show', window.scrollY>420); }
  window.addEventListener('scroll', onScroll); onScroll();
  if(back){ back.addEventListener('click', function(){ window.scrollTo({top:0,behavior:'smooth'}); }); }

  // === Drawer (modal com subtelas) ===
  var drawer=document.getElementById('drawer'),
      dClose=document.getElementById('drawerClose'),
      dBack=document.getElementById('drawerBack'),
      dTitle=document.getElementById('drawerTitle'),
      dBody=document.getElementById('drawerBody');
  if(dClose) dClose.addEventListener('click', closeDrawer);
  if(drawer){ drawer.addEventListener('click', function(e){ if(e.target===drawer) closeDrawer(); }); }

  var navStack = []; // pilha de telas
  var currentState = null;
  var drawerClosingTimer = null;

  function refreshNavControls(){
    if(dBack) dBack.style.display = navStack.length ? 'inline-flex' : 'none';
    if(dClose) dClose.style.display = navStack.length ? 'none' : 'inline-flex';
    if(drawer) drawer.classList.toggle('drawer--nested', navStack.length > 0);
  }

  function animateDrawer(direction){
    if(!dBody) return;
    dBody.classList.remove('drawer-anim-forward', 'drawer-anim-back', 'drawer-anim-root');
    void dBody.offsetWidth;
    var cls = 'drawer-anim-root';
    if(direction === 'forward') cls = 'drawer-anim-forward';
    else if(direction === 'back') cls = 'drawer-anim-back';
    dBody.classList.add(cls);
  }

  if(dBack) dBack.addEventListener('click', function(){
    var prev = navStack.pop();
    if(prev){ openDrawer(prev.title, prev.items, prev.kind, {restore:true}); }
  });

  function closeDrawer(){
    if(!drawer) return;
    if(drawerClosingTimer){ clearTimeout(drawerClosingTimer); drawerClosingTimer = null; }
    if(drawer.classList.contains('drawer--open')){
      drawer.classList.add('drawer--closing');
      drawer.classList.remove('drawer--full');
      body.classList.remove('no-scroll');
      navStack = []; currentState = null;
      refreshNavControls();
      drawerClosingTimer = setTimeout(function(){
        drawer.classList.remove('drawer--open','drawer--closing','drawer--nested');
        refreshNavControls();
        drawerClosingTimer = null;
      }, 260);
    }
  }

  function openDrawer(title, items, kind, opts){
    if(!drawer || !dBody || !dTitle) return;
    var direction = 'root';
    if(drawerClosingTimer){ clearTimeout(drawerClosingTimer); drawerClosingTimer = null; }
    drawer.classList.remove('drawer--closing');

    // empilha estado atual se navegando para dentro
    if(opts && opts.push && currentState){
      navStack.push(currentState);
      direction = 'forward';
    } else if(opts && opts.restore){
      direction = 'back';
    }
    refreshNavControls();

    // helpers
    function parseBRL(v){
      if(typeof v==='number') return v;
      if(v==null) return 0;
      var s=String(v).replace(/\s|R\$/g,'').trim();
      if(!s) return 0;
      s=s.replace(/\./g,'').replace(',', '.');
      var n=Number(s);
      return isNaN(n)?0:n;
    }

// Parse "YYYY-MM-DD" como data LOCAL (sem fuso/UTC)
function parseLocalISO(iso){
  if(!iso) return null;
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso));
  if(!m) return null;
  const y = +m[1], mo = +m[2]-1, d = +m[3];
  return new Date(y, mo, d); // <- local
}

// Timestamp local para sort
function timeLocal(iso){
  const d = parseLocalISO(iso);
  return d ? d.getTime() : 0;
}

// dd/mm/aaaa sem “voltar um dia”
function dmyLocal(iso){
  const d = parseLocalISO(iso);
  return d ? d.toLocaleDateString('pt-BR') : '—';
}

function safeDate(iso){ return parseLocalISO(iso); }


    function formatBRL(v){ return 'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2}); }
    function esc(str){ str = str === null ? '' : String(str); return str.replace(/[&<>"']/g,function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]||ch; }); }
    function escAttr(str){ return esc(str).replace(/\n/g,'&#10;'); }

    // normaliza lista base (array direto ou installments aninhados em objetos)
    var listSource = Array.isArray(items)
      ? items
      : (items && Array.isArray(items.installments) ? items.installments : []);

    var norm = listSource.map(function(i){
      return {
        entity: i.entity||'-',
        course: i.course||'',
        amount: parseBRL(i.amount),
        due_date: i.due_date||null,
        status: i.status||'',
        description: i.description||i.obs||i.note||null
      };
    });

    // agregações
    var total=0, byEntity={};
    norm.forEach(function(i){
      var amount=i.amount||0; total += amount;
      var ent=byEntity[i.entity]||(byEntity[i.entity]={sum:0,overdue:0,count:0,courses:{}});
      ent.sum+=amount; ent.count++; if(i.status==='overdue') ent.overdue+=amount;

      var course=ent.courses[i.course]||(ent.courses[i.course]={sum:0,overdue:0});
      course.sum+=amount; if(i.status==='overdue') course.overdue+=amount;
    });
    var uniqueEntities = Object.keys(byEntity).length;

    dTitle.textContent = title;
    var summaryHtml =
      '<div class="drawer__section">'+
        '<header class="drawer__section-head">'+
          '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(total)+'</div></div>'+
          '<div><span class="micro">Entidades</span><div class="drawer__number">'+uniqueEntities+'</div></div>'+
        '</header>'+
      '</div>';

    var dynamicHtml='';

    if(kind==='entity'){
      // detalhe da entidade
      var detail = items || {};
      var ent = detail.entity || null;
      var name = detail.name || title;
      var courses = ent && Array.isArray(ent.items) ? ent.items.slice() : [];
      var totalE = ent && typeof ent.total === "number" ? ent.total : courses.reduce(function(a,c){return a+(+c.value||0);},0);
      var receivedE = ent && typeof ent.received === "number" ? ent.received : courses.reduce(function(a,c){return a+(+c.received||0);},0);
      var pendingE = Math.max(0, totalE - receivedE);

      var sumHtml =
        '<div class="drawer__section">'+
          '<header class="drawer__section-head">'+
            '<div><span class="micro">Recebido</span><div class="drawer__number">'+formatBRL(receivedE)+'</div></div>'+
            '<div><span class="micro">Falta</span><div class="drawer__number">'+formatBRL(pendingE)+'</div></div>'+
            '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(totalE)+'</div></div>'+
          '</header>'+
          '<div class="micro">'+esc(name)+'</div>'+
        '</div>';

      var courseLines = courses.map(function(c){
        var value = Number(c.value)||0, rec = Number(c.received)||0, pend = Math.max(0, value - rec);
        return '<div class="info-line js-course" data-entity="'+escAttr(name)+'" data-course="'+escAttr(c.course||'-')+'">'+
                '<div><strong>'+esc(c.course||'-')+'</strong><span>'+formatBRL(rec)+' recebidos - '+formatBRL(pend)+' a receber</span></div>'+
                '<span class="tag">'+formatBRL(value)+'</span>'+
               '</div>';
      }).join('');
      var coursesHtml = courses.length
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Cursos</h4><span class="micro">Clique para ver detalhes</span></header><div class="drawer__list">'+courseLines+'</div></div>'
        : '<div class="drawer__section"><div class="alert">Nenhum curso disponível.</div></div>';

      // timeline de parcelas da entidade
      var relInst = Array.isArray(detail.installments) ? detail.installments.slice() : [];
     relInst.sort(function(a,b){
  var da = timeLocal(a.due_date);
  var db = timeLocal(b.due_date);
  return (db - da) || ((b.amount||0)-(a.amount||0)); // recente primeiro
});

      var timeline = relInst.map(function(item){
        var d=safeDate(item.due_date); var when=d? d.toLocaleDateString("pt-BR") : "-";
        var label=[item.course||"", statusLabel(item.status)].filter(Boolean).join(" - ");
        return '<div class="info-line"><div><strong>'+when+'</strong><span>'+esc(label)+'</span></div><span class="tag">'+formatBRL(item.amount)+'</span></div>';
      }).join('');
      var timelineHtml = timeline
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Parcelas relacionadas</h4></header><div class="drawer__list">'+timeline+'</div></div>'
        : '';

      dBody.innerHTML = sumHtml + coursesHtml + timelineHtml;
      // abre o drawer e atualiza estado (igual ao branch de pending/paid/overdue)
animateDrawer('forward'); // animação do drawer
if (!drawer.classList.contains('drawer--open')) {
  drawer.classList.add('drawer--open');
}
body.classList.add('no-scroll');
drawer.classList.add('drawer--full');

currentState = { title: title, items: items, kind: kind || 'entity' }; // mantém o estado
refreshNavControls(); // atualiza controles de navegação


   } else if (kind==='course') {
  var detail = items || {};
  var course = detail.course || null;
  var entityName = detail.entityName || "";
  var courseName = detail.courseName || title;

  var totalC    = course && typeof course.value === "number"    ? course.value    : 0;
  var receivedC = course && typeof course.received === "number" ? course.received : 0;
  var pendingC  = Math.max(0, totalC - receivedC);

  // meta (datas, ch e classificação se vier)
  var ch     = course && course.ch ? String(course.ch) : (detail.ch || '-');
  var dIni   = course && course.date_start ? course.date_start : (detail.date_start || null);
  var dFim   = course && course.date_end   ? course.date_end   : (detail.date_end   || null);
  var classi = (course && course.class) || (detail.class) || '';

  function metaPill(label, value){
    if(!value || value === '-') return '';
    return '<span class="pill">'+label+': '+esc(value)+'</span>';
  }


  var subtitleClass = 'micro course-subtitle';
  if ((courseName || '').length > 48) {
    subtitleClass += ' course-subtitle--long';
  }

  var header =
    '<div class="drawer__section">'+
      '<header class="drawer__section-head">'+
        '<div>'+
          '<span class="micro">Recebido</span>'+
          '<div class="drawer__number">'+formatBRL(receivedC)+'</div>'+
        '</div>'+
        '<div>'+
          '<span class="micro">Falta</span>'+
          '<div class="drawer__number">'+formatBRL(pendingC)+'</div>'+
        '</div>'+
        '<div>'+
          '<span class="micro">Total</span>'+
          '<div class="drawer__number">'+formatBRL(totalC)+'</div>'+
        '</div>'+
      '</header>'+
      '<div class="chips">'+
        metaPill('Entidade', entityName)+
        metaPill('Carga Horária', ch)+
        metaPill('Início', dmyLocal(dIni))+
        metaPill('Fim', dmyLocal(dFim))+
        (classi ? '<span class="pill">'+esc(classi.toUpperCase())+'</span>' : '')+
      '</div>'+
      '<div class="'+subtitleClass+'">'+esc(entityName)+' &mdash; '+esc(courseName)+'</div>'+
    '</div>';

  // extrato (parcelas relacionadas)
  var relInst = Array.isArray(detail.installments) ? detail.installments.slice() : [];
  relInst.sort(function(a,b){
    var da=a.due_date?timeLocal(a.due_date):0;
    var db=b.due_date?timeLocal(b.due_date):0;
    return (db-da) || ((b.amount||0)-(a.amount||0)); // recente primeiro
  });

  var lines = relInst.map(function(item){
    var when   = item.due_date ? dmyLocal(item.due_date) : '-';

    var status = statusLabel(item.status);
    var chip   = '';
    // se vierem inst_no/inst_total, mostra a “bolacha” da parcela
    if (typeof item.inst_no === 'number') {
      chip = '<span class="chip" style="margin-left:8px">'+
               (item.inst_total ? (item.inst_no+'/'+item.inst_total) : (item.inst_no+'ª parcela'))+
             '</span>';
    }
    return '<div class="info-line">'+
             '<div><strong>'+when+'</strong><span>'+esc(status)+' — '+esc(courseName)+'</span>'+chip+'</div>'+
             '<span class="tag">'+formatBRL(item.amount)+'</span>'+
           '</div>';
  }).join('');

  var installmentsHtml =
    '<div class="drawer__section">'+
      '<header class="drawer__section-head"><h4>Extrato de parcelas</h4></header>'+
      (lines ? '<div class="drawer__list">'+lines+'</div>' : '<div class="alert">Nenhuma parcela encontrada.</div>')+
    '</div>';

  dBody.innerHTML = header + installmentsHtml;

// abre o drawer e atualiza estado (igual ao branch de pending/paid/overdue)
animateDrawer('forward'); // animação do drawer
if (!drawer.classList.contains('drawer--open')) {
  drawer.classList.add('drawer--open');
}
body.classList.add('no-scroll');
drawer.classList.add('drawer--full');

currentState = { title: title, items: items, kind: kind || 'entity' }; // mantém o estado
refreshNavControls(); // atualiza controles de navegação

} else if (kind==='overdue' || kind==='pending' || kind==='paid') {
  var items = norm.slice();

  // ==== EXTRATO ESPECIAL PARA "RECEBIDO" ====
  if (kind === 'paid') {
    // 1) agrupa por (entidade+curso) para numerar parcelas
    var buckets = {}; // key: ent|course -> [{...}]
    items.forEach(function(i){
      var key = (i.entity||'-')+'|'+(i.course||'-');
      (buckets[key]||(buckets[key]=[])).push(i);
    });

    // 2) dentro de cada curso, ordena por data ASC e marca "install_no"
    Object.keys(buckets).forEach(function(k){
    buckets[k].sort(function(a,b){
  var da = timeLocal(a.due_date);
  var db = timeLocal(b.due_date);
  return da - db; // ascendente
});

      buckets[k].forEach(function(it, idx){
        it.__install_no = idx + 1;              // 1,2,3...
        // se no futuro helpers trouxer "inst_total", usamos; senão deixamos só ordinal
        it.__install_total = (typeof it.inst_total === 'number' && it.inst_total>0) ? it.inst_total : null;
      });
    });

    // 3) ordena lista final por data DESC (extrato: mais recente no topo)
 items.sort(function(a,b){
  var da = timeLocal(a.due_date);
  var db = timeLocal(b.due_date);
  return (db - da) || ((b.amount||0) - (a.amount||0));
});


  } else {
    // pendentes/vencidos: mantém comportamento atual (por data DESC)
    items.sort(function(a,b){
      var da = a.due_date ? timeLocal(a.due_date) : 0;
      var db = b.due_date ? timeLocal(b.due_date) : 0;
      return (db - da) || ((b.amount||0) - (a.amount||0));
    });
  }

  // render
  var listItems = items.map(function(i){
    var subtitle = [i.entity||'', i.course||''].filter(Boolean).join(' - ');
   var when = (i.due_date ? dmyLocal(i.due_date) : '-');


    // chip de parcela só quando for "Recebido"
    var parcelaChip = '';
    if (kind === 'paid' && i.__install_no) {
      var label = i.__install_total ? (i.__install_no + '/' + i.__install_total) : (i.__install_no + 'ª parcela');
      parcelaChip = '<span class="chip" style="margin-left:8px">'+esc(label)+'</span>';
    }

    return '<div class="info-line js-course" '+
           ' data-entity="'+escAttr(i.entity||'-')+'" '+
           ' data-course="'+escAttr(i.course||'-')+'" '+
           ' data-inline-open="1"'+
           ' onclick="var ev=event||window.event; if(ev){ev.stopPropagation();} window.dashboardOpenCourse(\''+escAttr(i.entity||'-')+'\', \''+escAttr(i.course||'-')+'\'); return false;">'+
             '<div><strong>'+when+'</strong><span>'+esc(subtitle)+'</span>'+parcelaChip+'</div>'+
             '<span class="tag">'+formatBRL(i.amount)+'</span>'+
           '</div>';
  }).join('');

  var html = '<div class="drawer__section">'+
               '<header class="drawer__section-head"><h4>Itens</h4></header>'+
               '<div class="drawer__list">'+(listItems || '<div class="alert">Nada aqui.</div>')+'</div>'+
             '</div>';

  dBody.innerHTML = summaryHtml + html;
  animateDrawer(direction);
  if(!drawer.classList.contains('drawer--open')){
    drawer.classList.add('drawer--open');
  }
  body.classList.add('no-scroll');
  drawer.classList.add('drawer--full');

  // estado atual
  currentState = { title: title, items: items, kind: kind||'pending' };
  refreshNavControls();
}
}

  // Abridores (KPIs + entidades/curso da lista)
  function bindOpeners(){
    // Função que abre o drawer de acordo com a KPI
    function handle(kind){
      var raw = Array.isArray(datasetAll) ? datasetAll : [];
      var items = raw.filter(function(i){
        if(kind === 'pending') {
          // A Receber = pendentes + vencidos
          return (i.status === 'pending' || i.status === 'overdue');
        }
        return i.status === kind; // 'paid' ou 'overdue'
      });
      var ttl = (kind === 'paid') ? 'Recebidos' : (kind === 'overdue' ? 'Vencidos' : 'A Receber');
      openDrawer(ttl, items, kind, {push:false});
    }

    // KPIs: clique e teclado
    var kpies = document.querySelectorAll('.kpi[data-open]');
    for (var i = 0; i < kpies.length; i++) {
      (function(el){
        function trigger(){ handle(String(el.getAttribute('data-open')||'pending')); }
        el.addEventListener('click', trigger);
        el.addEventListener('keydown', function(e){
          if(e.key==='Enter' || e.key===' '){
            e.preventDefault();
            trigger();
          }
        });
      })(kpies[i]);
    }

    // entidade/curso (cards/lista)
    document.addEventListener('click', function (e) {
      var elEnt = e.target.closest('.entity-action.js-entity, .card.js-entity, .info-line.js-entity, .entity.js-entity');
      if (elEnt) {
        var name = (elEnt.getAttribute('data-entity') || '').trim() || elEnt.textContent.trim();
        var detail = collectEntityDetail(name);
        openDrawer(name, detail, 'entity', {push:true});
        return;
      }
      var elCourse = e.target.closest('.js-course');
      if (elCourse) {
        if(elCourse.getAttribute('data-inline-open') === '1'){ return; }
        var en = elCourse.getAttribute('data-entity') || '';
        var cn = elCourse.getAttribute('data-course') || '';
        var detail = collectCourseDetail(en, cn);
        openDrawer(cn || 'Curso', detail, 'course', {push:true});
      }
    });
  }
  bindOpeners();
  refreshNavControls();

  // abrir curso de qualquer lugar (usado pelos itens do "Recebido")
  window.dashboardOpenCourse = function(en, cn){
    var detail = collectCourseDetail(en || '', cn || '');
    openDrawer(cn || 'Curso', detail, 'course', {push:true});
  };

  // expõe para os onClick inline dos cards de KPI
  window.dashboardOpenStatus = function(kind){
    var raw = Array.isArray(datasetAll) ? datasetAll : [];
    var items = raw.filter(function(i){
      if(kind === 'pending'){ return (i.status==='pending' || i.status==='overdue'); }
      return i.status === kind; // 'paid' ou 'overdue'
    });
    var ttl = (kind === 'paid') ? 'Recebidos' : (kind === 'overdue' ? 'Vencidos' : 'A Receber');
    openDrawer(ttl, items, kind, {push:false});
  };

  // ESC fecha modal/drawer
  window.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      if(modal && modal.classList.contains('modal--open')){ closeModal(); }
      closeDrawer();
    }
  });
})();
</script>
</body>
</html>
