<?php
// public/index.php — UI/Exibição
session_start();

// UTF-8 sempre
header('Content-Type: text/html; charset=UTF-8');
ini_set('default_charset', 'UTF-8');
mb_internal_encoding('UTF-8');

require __DIR__.'/../app/helpers.php';
$cfg = require __DIR__.'/../app/config.php';

$logged = true;   // <- força logado
$data   = get_data(false);

// Ações básicas
$action = $_POST['action'] ?? $_GET['action'] ?? null;


// Sync
if (isset($_SESSION['u']) && $action === 'sync' && $_SERVER['REQUEST_METHOD'] === 'POST') {
  get_data(true); header('Location: ./?synced=1'); exit;
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
function dmy($iso){ return $iso ? date('d/m/Y', strtotime($iso)) : '-'; }

$ultimaSync = $logged ? human_ago($data['created_at'] ?? null) : '-';

// -------- filtros (chips) --------
// FIX do warning: whitelist + fallback
$__viewParam = $_GET['view'] ?? 'list';
$__allowedViews = ['list','grid','carousel'];
$view = in_array($__viewParam, $__allowedViews, true) ? $__viewParam : 'list';

$Q = [
  'q'         => trim($_GET['q'] ?? ''),
  'status'    => $_GET['status'] ?? 'all',   // all|pending|overdue|paid
  'month'     => trim($_GET['month'] ?? ''), // YYYY-MM
  'due_in'    => (int)($_GET['due_in'] ?? 7) // 7|15|30
];

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
function month_human($ym, $names){
  if(!$ym || strpos($ym, '-')===false) return $ym;
  [$y,$m] = explode('-',$ym);
  $m = (int)$m;
  $label = $names[$m] ?? $m;
  return $label.'-'.$y;
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

// aplica filtros na lista de parcelas
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

// Export CSV (respeita filtros atuais)
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
      $i['status'] ?? ''
    ], ';');
  }
  fclose($out);
  exit;
}

// totais filtrados (para KPIs)
$totRec=$totRcv=$totOvd=0.0;
foreach($filteredInstallments as $i){
  $st = $i['status'] ?? '';
  if(in_array($st,['pending','overdue'],true)) $totRec+=(float)$i['amount'];
  if($st==='paid') $totRcv+=(float)$i['amount'];
  if($st==='overdue') $totOvd+=(float)$i['amount'];
}

// agrupa por entidade para a lista visual
$groupFiltered=[];
if($logged && !empty($data['entities'])){
  // DESC: só remove "-" se houver ao menos uma entidade real
  $hasReal = false;
  foreach($data['entities'] as $e){
    if(isset($e['name']) && trim($e['name'])!=='-'){ $hasReal = true; break; }
  }

  foreach($data['entities'] as $e){
    if($hasReal && isset($e['name']) && trim($e['name'])==='-') continue; // remove "-" apenas se existir outra entidade
    $bucket=['name'=>$e['name'] ?? '-', 'items'=>[], 'total'=>0, 'received'=>0];

    foreach($e['items'] as $it){
      $matchesQ = $Q['q'] ? (mb_stripos(mb_strtolower(($e['name'] ?? '').' '.($it['course'] ?? ''),'UTF-8'), mb_strtolower($Q['q'],'UTF-8'))!==false) : true;
      if(!$matchesQ) continue;

      // quando há filtro por status/mês, exibe apenas se existir parcela daquele curso nesse filtro
      if($Q['status']!=='all' || $Q['month']){
        $exists=false;
        foreach($filteredInstallments as $pi){
          if(($pi['entity']??'')===$e['name'] && ($pi['course']??'')===($it['course']??'')){ $exists=true; break; }
        }
        if(!$exists) continue;
      }

      $bucket['items'][]=$it;
      $bucket['total']+=(float)($it['value'] ?? 0);
      $bucket['received']+=(float)($it['received'] ?? 0);
    }
    if($bucket['items']) $groupFiltered[]=$bucket;
  }
}

$FIRST_GROUPS = 9999; // mostra tudo aberto
$visibleGroups = $groupFiltered;
$hiddenGroups  = [];

// flag de filtro aplicado
$hasFilter = ($Q['status']!=='all' || $Q['month']!=='' || $Q['q']!=='');

?>
<!doctype html>
<html lang="pt-br" class="theme-dark">
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

    /* 2) Agenda 6m responsiva (evita overflow lateral) */
    .drawer .forecast { grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)) !important; }

    /* 3) cursor de clique em tudo que abre subtela */
    .kpi, .info-line, .js-entity, .js-course, .js-day, .js-due, .chip--toggle { cursor: pointer; }

    /* 4) animação “iOS-like” para o drawer */
    .drawer{ transition:opacity .28s ease; }
    .drawer__panel{ transform:translateX(60px) scale(.98); opacity:.98; }
    .drawer--open .drawer__panel{
      animation:ios-in .32s cubic-bezier(.22,.8,.16,1) both;
    }
    @keyframes ios-in{
      0%{ transform:translateX(60px) scale(.98); opacity:.0; }
      60%{ transform:translateX(0) scale(1.005); opacity:1; }
      100%{ transform:translateX(0) scale(1); opacity:1; }
    }
  </style>
</head>
<body class="bgfx theme-dark">

<?php if(!$logged): ?>
  <!-- LOGIN -->
  <div class="container" style="display:flex;min-height:100vh;align-items:center;justify-content:center">
    <div class="card" style="width:420px">
      <div class="brand" style="margin-bottom:12px">
        <div class="logo"></div>
        <div>
          <div class="title"><?= htmlspecialchars($cfg['APP_NAME'] ?? 'App', ENT_QUOTES, 'UTF-8') ?></div>
          <div class="badge">acesso restrito</div>
        </div>
      </div>
      <?php if(!empty($error)): ?>
        <div class="alert" style="border-style:solid;border-color:#7f1d1d;background:#180e0e;color:#fda4a4">
          <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
      <form method="post" style="display:flex;flex-direction:column;gap:10px;margin-top:8px" autocomplete="off" novalidate>
        <input type="hidden" name="action" value="login">
        <label for="email">E-mail</label><input class="input" id="email" name="email" type="email" required>
        <label for="password">Senha</label><input class="input" id="password" name="password" type="password" required>
        <button class="btn btn--primary" style="justify-content:center">Entrar</button>
      </form>
    </div>
  </div>

<?php else: ?>
  <div class="container">
    <!-- HERO -->
    <div class="hero" role="banner">
      <div class="hero__art" aria-hidden="true">
        <span class="hero__orb"></span>
        <span class="hero__initials">JML</span>
        <span class="hero__halo"></span>
      </div>
      <div class="hero__text">
        <span class="hero__tag">JML - Estúdio Financeiro</span>
        <h1 class="hero__title"><?= htmlspecialchars($cfg['APP_NAME'] ?? 'App', ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="hero__subtitle">Curadoria artística de dados para o universo JML.</p>
      </div>
      <div class="hero__actions">
        <button id="btnConfig" class="btn hero__btn" type="button">&#9881; Configurar</button>
        <form method="post" class="hero__form">
          <input type="hidden" name="action" value="sync">
          <button class="btn btn--primary hero__btn" title="Última sincronização: <?= htmlspecialchars($ultimaSync, ENT_QUOTES, 'UTF-8') ?>">Sincronizar agora</button>
        </form>
      </div>
    </div>

    <!-- KPIs -->
    <?php
      $receivable = (float)$totRec;  $received=(float)$totRcv;  $overdue=(float)$totOvd;
      $base=max(1,$receivable+$received); $pctRec=min(100,round($receivable/$base*100)); $pctRcvd=min(100,round($received/$base*100));
      $pctOvd=$base>0?min(100,round($overdue/$base*100)):0;
    ?>
    <div class="grid kpis">
      <div class="card kpi kpi--rec" data-open="pending" tabindex="0" role="button" aria-label="Abrir detalhes: A Receber">
        <h4>A Receber<?= $hasFilter ? ' (filtrado)' : '' ?></h4>
        <div class="amount"><?= brl($receivable) ?></div>
        <div class="progress"><div class="bar bar--rec" style="width:<?= $pctRec ?>%"></div></div>
        <div class="sub">lançamentos pendentes e futuros</div>
      </div>
      <div class="card kpi kpi--rcv" data-open="paid" tabindex="0" role="button" aria-label="Abrir detalhes: Recebido">
        <h4>Recebido<?= $hasFilter ? ' (filtrado)' : '' ?></h4>
        <div class="amount amount--ok"><?= brl($received) ?></div>
        <div class="progress"><div class="bar bar--rcv" style="width:<?= $pctRcvd ?>%"></div></div>
        <div class="sub">somatório marcado como pago</div>
      </div>
      <div class="card kpi kpi--ovd" data-open="overdue" tabindex="0" role="button" aria-label="Abrir detalhes: Vencidos">
        <h4>Vencidos<?= $hasFilter ? ' (filtrado)' : '' ?></h4>
        <div class="amount amount--bad"><?= brl($overdue) ?></div>
        <div class="progress"><div class="bar bar--ovd" style="width:<?= $pctOvd ?>%"></div></div>
        <div class="sub"><span class="chip chip--danger">atenção</span></div>
      </div>
    </div>

    <!-- Vencendo em X dias -->
    <div class="section-title">Vencendo em
      <?php
        $validDueIn = in_array($Q['due_in'], [7,15,30], true) ? $Q['due_in'] : 7;
        echo ' '.$validDueIn.' dias';
      ?>
    </div>
    <div class="card" style="margin-bottom:14px">
      <div class="chips" style="margin-bottom:12px">
        <?php foreach([7,15,30] as $di):
          $active = $validDueIn===$di ? 'is-active' : '';
          $href = '?'.qstr(['due_in'=>$di]);
        ?>
          <a class="chip chip--toggle <?= $active ?>" href="<?= $href ?>"><?= $di ?> dias</a>
        <?php endforeach; ?>
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
            <div class="item" data-tip="<?= htmlspecialchars(($u['entity']??'').' - '.($u['course']??'').' - Venc.: '.dmy($u['due_date']??'').' - '.brl((float)($u['amount']??0)).' - '.strtoupper($u['status']??''), ENT_QUOTES, 'UTF-8') ?>">
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
    <div class="section-title" style="display:flex;align-items:center;gap:12px">
      <span>Entidades <?= $hasFilter ? '<small class="chip">filtrado</small>' : '' ?></span>
      <div class="chips">
        <?php
          $tabs = ['list'=>'Lista','grid'=>'Grade','carousel'=>'Carrossel'];
          foreach($tabs as $k=>$lbl):
            $active = $view===$k ? 'is-active' : '';
        ?>
          <a class="chip chip--toggle <?= $active ?>" href="?<?= qstr(['view'=>$k]) ?>"><?= $lbl ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- FILTERS -->
    <div class="filters filters--list">
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
        <span class="filters__label">Mês</span>
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
          <?php if($Q['status']!=='all'): ?><input type="hidden" name="status" value="<?= htmlspecialchars($Q['status'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <?php if($Q['month']!==''): ?><input type="hidden" name="month" value="<?= htmlspecialchars($Q['month'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
          <?php if(in_array($Q['due_in'],[7,15,30],true)): ?><input type="hidden" name="due_in" value="<?= (int)$Q['due_in'] ?>"><?php endif; ?>
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
            $entTotal   = (float)($e['total'] ?? 0);
            $entReceived= (float)($e['received'] ?? 0);
            $pct        = $entTotal > 0 ? min(100, round(($entReceived / $entTotal) * 100)) : 0;
          ?>
          <div class="card js-entity" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-tip="<?= htmlspecialchars('Total '.brl($entTotal).' - Recebido '.brl($entReceived), ENT_QUOTES, 'UTF-8') ?>">
            <div class="entity" style="margin-bottom:8px"><?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="progress"><div class="bar" style="width:<?= $pct ?>%"></div></div>
            <div class="chips" style="margin-top:10px">
              <span class="chip">Recebido <?= brl($entReceived) ?></span>
              <span class="chip">Falta <?= brl(max(0,$entTotal-$entReceived)) ?></span>
              <span class="chip mono">Total <?= brl($entTotal) ?></span>
            </div>
            <div class="list" style="margin-top:12px">
              <?php foreach($e['items'] as $it):
                $courseValue    = (float)($it['value'] ?? 0);
                $courseReceived = (float)($it['received'] ?? 0);
                $coursePending  = (float)($it['pending'] ?? 0);
                $coursePct      = $courseValue > 0 ? min(100, round(($courseReceived / $courseValue) * 100)) : 0;

                $chips = [];
                if ($Q['status']!=='all') $chips[] = strtoupper($Q['status']);
                if ($Q['month']!=='')     $chips[] = month_human($Q['month'],$MONTH_NAMES);
              ?>
                <div class="list-item js-course" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-course="<?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?>">
                  <div class="list-item__body">
                    <div class="list-item__course">
                      <strong class="list-item__title"><?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong>
                      <span class="list-item__split"><?= brl($courseReceived) ?> recebidos - <?= brl($coursePending) ?> a receber</span>
                    </div>
                    <div class="list-item__values">
                      <span class="list-item__total"><?= brl($courseValue) ?></span>
                      <?php if($chips): ?>
                        <div class="list-item__tags chips" style="justify-content:flex-end">
                          <?php foreach($chips as $c): ?><span class="chip"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="progress progress--item"><div class="bar" style="width:<?= $coursePct ?>%"></div></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
        <?php if(!$groupFiltered): ?><div class="alert">Nenhum resultado para os filtros atuais.</div><?php endif; ?>
      </div>

    <?php elseif($view==='carousel'): ?>
      <!-- CARROSSEL -->
      <div class="card" style="overflow:hidden">
        <div style="display:flex; gap:16px; overflow:auto; scroll-snap-type:x mandatory; padding-bottom:6px">
          <?php foreach($groupFiltered as $e): ?>
            <?php $entTotal=(float)$e['total']; $entReceived=(float)$e['received']; $pct=$entTotal>0?min(100,round($entReceived/$entTotal*100)):0; ?>
            <div class="card js-entity" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" style="min-width:340px; scroll-snap-align:start">
              <div class="entity" style="margin-bottom:8px"><?= htmlspecialchars($e['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
              <div class="progress"><div class="bar" style="width:<?= $pct ?>%"></div></div>
              <div class="chips" style="margin-top:10px">
                <span class="chip">Recebido <?= brl($entReceived) ?></span>
                <span class="chip">Falta <?= brl(max(0,$entTotal-$entReceived)) ?></span>
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
            $entTotal    = (float)($e['total'] ?? 0);
            $entReceived = (float)($e['received'] ?? 0);
            $pct         = $entTotal > 0 ? min(100, round(($entReceived / $entTotal) * 100)) : 0;
          ?>
          <details open>
            <summary>
              <div class="entity js-entity" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
              </div>
              <div class="chips">
                <span class="chip">Recebido <?= brl($entReceived) ?></span>
                <span class="chip">Falta <?= brl(max(0,$entTotal-$entReceived)) ?></span>
                <span class="chip mono">Total <?= brl($entTotal) ?></span>
              </div>
            </summary>

            <div class="group-body">
              <div class="progress progress--group"><div class="bar" style="width:<?= $pct ?>%"></div></div>

              <?php foreach($e['items'] as $it):
                $courseValue    = (float)($it['value'] ?? 0);
                $courseReceived = (float)($it['received'] ?? 0);
                $coursePending  = (float)($it['pending'] ?? 0);
                $coursePct      = $courseValue > 0 ? min(100, round(($courseReceived / $courseValue) * 100)) : 0;

                $tipParts = array_filter([
                  $e['name'] ?? '',
                  $it['course'] ?? '',
                  'Total '.brl($courseValue),
                  'Recebidos '.brl($courseReceived),
                  'Falta '.brl($coursePending)
                ]);
                $tip = htmlspecialchars(implode(' - ', $tipParts), ENT_QUOTES, 'UTF-8');

                // chips de status/mês (do filtro atual) à direita
                $chips = [];
                if ($Q['status']!=='all') $chips[] = strtoupper($Q['status']);
                if ($Q['month']!=='')     $chips[] = month_human($Q['month'],$MONTH_NAMES);
              ?>
                <div class="list-item js-course" data-entity="<?= htmlspecialchars($e['name'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-course="<?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?>" data-tip="<?= $tip ?>">
                  <div class="list-item__body">
                    <div class="list-item__course">
                      <div class="list-item__meta">
                        <span class="pill"><?= htmlspecialchars($it['range'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="pill">CH <?= htmlspecialchars($it['ch'] ?? '-', ENT_QUOTES, 'UTF-8') ?></span>
                      </div>
                      <strong class="list-item__title"><?= htmlspecialchars($it['course'] ?? '-', ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="list-item__values">
                      <span class="list-item__total"><?= brl($courseValue) ?></span>
                      <span class="list-item__split"><?= brl($courseReceived) ?> recebidos - <?= brl($coursePending) ?> a receber</span>

                      <?php if($chips): ?>
                        <div class="list-item__tags chips" style="justify-content:flex-end">
                          <?php foreach($chips as $c): ?><span class="chip"><?= htmlspecialchars($c, ENT_QUOTES, 'UTF-8') ?></span><?php endforeach; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <div class="progress progress--item"><div class="bar" style="width:<?= $coursePct ?>%"></div></div>
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
        <div class="card" style="margin-bottom:14px">
          <h4>Tema</h4>
          <div class="opt">
            <label><input type="radio" name="theme" value="dark"> Dark</label>
            <label><input type="radio" name="theme" value="light"> Light</label>
          </div>
        </div>

        <div class="card">
          <h4>Cor de destaque</h4>
          <div class="hue">
            <div class="hue__track"></div>
            <input id="hue" type="range" min="0" max="360" step="1">
            <div class="hue__swatches">
              <?php foreach([145,200,260,300,20,60,100] as $h): ?>
                <div class="swatch" data-h="<?= (int)$h ?>" style="background:hsl(<?= (int)$h ?> 85% 55%)"></div>
              <?php endforeach; ?>
              <div id="hueNow" class="swatch" title="Atual" style="box-shadow:0 0 0 2px #fff6, inset 0 0 0 2px #0002"></div>
            </div>
          </div>
        </div>

        <div class="sub" style="margin-top:10px">Última sync: <?= htmlspecialchars($ultimaSync, ENT_QUOTES, 'UTF-8') ?></div>
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
    var ent = findEntity(name);
    var rel = filteredInstallments.filter(function(item){
      return (item.entity||'') === name;
    });
    if(rel.length === 0 && Array.isArray(datasetAll)){
      rel = datasetAll.filter(function(item){ return (item.entity||'') === name; });
    }
    return { entity: ent, name: name, installments: rel };
  }
  function collectCourseDetail(entityName, courseName){
    var ent = findEntity(entityName);
    var courseInfo = null;
    if(ent && Array.isArray(ent.items)){
      for(var i=0;i<ent.items.length;i++){
        var itm = ent.items[i];
        if((itm.course||'') === courseName){ courseInfo = itm; break; }
      }
    }
    var rel = filteredInstallments.filter(function(item){
      return (item.entity||'') === entityName && (item.course||'') === courseName;
    });
    if(rel.length === 0 && Array.isArray(datasetAll)){
      rel = datasetAll.filter(function(item){ return (item.entity||'') === entityName && (item.course||'') === courseName; });
    }
    return { entityName: entityName, courseName: courseName, course: courseInfo, installments: rel };
  }

  // === Tema persistente ===
  function setTheme(t){
    if(!t) t='dark';
    // MIGRA: só aceita 'dark' ou 'light'
    if(t!=='dark' && t!=='light'){ t='dark'; try{ localStorage.setItem('julieta:theme','dark'); }catch(e){} }
    ['theme-dark','theme-light'].forEach(function(c){ root.classList.remove(c); body.classList.remove(c); });
    var cn = 'theme-'+t;
    root.classList.add(cn); body.classList.add(cn);
    root.dataset.theme = t; body.dataset.theme = t;
    try{ localStorage.setItem('julieta:theme', t); }catch(e){}
    var r = Array.prototype.slice.call(document.querySelectorAll('input[name="theme"]')).find(function(r){return r.value===t;});
    if(r) r.checked=true;
  }
  function setHue(h){
    h = String(h||'145');
    root.style.setProperty('--accent-h', h);
    try{ localStorage.setItem('julieta:hue', h);}catch(e){}
    var hueInp=document.getElementById('hue'), hueNow=document.getElementById('hueNow');
    if(hueInp) hueInp.value=h;
    if(hueNow) hueNow.style.background='hsl('+h+' 85% 55%)';
  }
  try{ setTheme((localStorage.getItem('julieta:theme')||'dark')); }catch(e){ setTheme('dark'); }
  try{
    var storedHue = localStorage.getItem('julieta:hue');
    if(!storedHue || isNaN(+storedHue)) storedHue='145';
    setHue(storedHue);
  }catch(e){ setHue('145'); }

  // modal simples (config)
  var modal=document.getElementById('modal');
  var openB=document.getElementById('btnConfig');
  var closeB=document.getElementById('modalClose');
  if(openB) openB.addEventListener('click', function(){ if(modal && !modal.classList.contains('modal--open')){ modal.classList.add('modal--open'); body.classList.add('no-scroll'); }});
  if(closeB) closeB.addEventListener('click', function(){ if(modal && modal.classList.contains('modal--open')){ modal.classList.remove('modal--open'); body.classList.remove('no-scroll'); }});
  if(modal){ modal.addEventListener('click', function(e){ if(e.target===modal){ modal.classList.remove('modal--open'); body.classList.remove('no-scroll'); } }); }

  // hue
  var hueInp=document.getElementById('hue');
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

  if(dBack) dBack.addEventListener('click', function(){
    var prev = navStack.pop();
    if(prev){ openDrawer(prev.title, prev.items, prev.kind, {restore:true}); }
  });

  function closeDrawer(){
    if(!drawer) return;
    if(drawer.classList.contains('drawer--open')){
      drawer.classList.remove('drawer--open');
      drawer.classList.remove('drawer--full');
      body.classList.remove('no-scroll');
      navStack = []; currentState = null;
      if(dBack) dBack.style.display='none';
    }
  }

  function openDrawer(title, items, kind, opts){
    if(!drawer || !dBody || !dTitle) return;

    // empilha estado atual se navegando para dentro
    if(opts && opts.push && currentState){ navStack.push(currentState); }
    // controla visibilidade do botão Voltar
    if(dBack) dBack.style.display = navStack.length ? 'inline-flex' : 'none';

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
    function safeDate(iso){
      if(!iso) return null;
      var d=new Date(iso);
      return isNaN(d.getTime())?null:d;
    }
    function formatBRL(v){ return 'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2}); }
    function esc(str){ str = str === null ? '' : String(str); return str.replace(/[&<>"']/g,function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]||ch; }); }
    function escAttr(str){ return esc(str).replace(/\n/g,'&#10;'); }
    function daysDiff(iso){
      var d=safeDate(iso); if(!d) return 0;
      var t=new Date(); t.setHours(0,0,0,0); d.setHours(0,0,0,0);
      return Math.floor((d - t)/86400000);
    }
    function tipFrom(i){
      var parts=[];
      if(i.entity) parts.push(i.entity);
      if(i.course) parts.push(i.course);
      if(i.due_date){ var dd=safeDate(i.due_date); parts.push('Venc.: '+(dd? dd.toLocaleDateString('pt-BR') : '-')); }
      if(i.amount!=null){ parts.push(formatBRL(parseBRL(i.amount))); }
      if(i.status) parts.push(String(i.status).toUpperCase());
      if(i.description) parts.push(i.description);
      return parts.join('\n');
    }

    // normaliza itens
    var norm = (items||[]).map(function(i){
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
    var total=0, byEntity={}, byMonth={};
    norm.forEach(function(i){
      var amount=i.amount||0; total += amount;
      var ent=byEntity[i.entity]||(byEntity[i.entity]={sum:0,overdue:0,count:0,courses:{}});
      ent.sum+=amount; ent.count++; if(i.status==='overdue') ent.overdue+=amount;

      var course=ent.courses[i.course]||(ent.courses[i.course]={sum:0,overdue:0,nextDue:null,status:i.status});
      course.sum+=amount; if(i.status==='overdue') course.overdue+=amount;
      var due=safeDate(i.due_date); if(due && (!course.nextDue || due<course.nextDue)) course.nextDue=due;

      var ym = due ? due.toISOString().slice(0,7) : 'sem-data';
      var bucket=byMonth[ym]||(byMonth[ym]={sum:0,count:0,items:[]});
      bucket.sum+=amount; bucket.count++; bucket.items.push(i);
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

    // estado global de faixas de prazo (só em "pending")
    var ranges = [
      {key:'r0_7',  label:'0–7d',   test:function(d,st){ return st!=='paid' && d>=0 && d<=7; }},
      {key:'r8_15', label:'8–15d',  test:function(d,st){ return st!=='paid' && d>=8 && d<=15; }},
      {key:'r16_30',label:'16–30d', test:function(d,st){ return st!=='paid' && d>=16 && d<=30; }},
      {key:'r31p',  label:'>30d',   test:function(d,st){ return st!=='paid' && d>30; }},
      {key:'ovd',   label:'Vencidos',test:function(d,st){ return st==='overdue'; }}
    ];
    window.__dueActive = window.__dueActive || null;
    function applyRange(items){
      if(!window.__dueActive || kind!=='pending') return items;
      var rng = ranges.find(function(r){return r.key===window.__dueActive;});
      if(!rng) return items;
      return items.filter(function(i){ return rng.test(daysDiff(i.due_date), String(i.status||'')); });
    }
    var normFiltered = applyRange(norm);

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
        var da=safeDate(a.due_date), db=safeDate(b.due_date);
        if(da && db) return da - db;
        if(da && !db) return -1;
        if(!da && db) return 1;
        return (a.amount||0) - (b.amount||0);
      });
      var timeline = relInst.map(function(item){
        var d=safeDate(item.due_date); var when=d? d.toLocaleDateString("pt-BR") : "-";
        var label=[item.course||"", String(item.status||"").toUpperCase()].filter(Boolean).join(" - ");
        return '<div class="info-line" data-tip="'+escAttr(tipFrom(item))+'"><div><strong>'+when+'</strong><span>'+esc(label)+'</span></div><span class="tag">'+formatBRL(item.amount)+'</span></div>';
      }).join('');
      var timelineHtml = timeline
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Parcelas relacionadas</h4></header><div class="drawer__list">'+timeline+'</div></div>'
        : '';

      dBody.innerHTML = sumHtml + coursesHtml + timelineHtml;

    } else if(kind==='course'){
      // detalhe do curso
      var detail = items || {};
      var course = detail.course || null;
      var entityName = detail.entityName || "";
      var courseName = detail.courseName || title;
      var totalC = course && typeof course.value === "number" ? course.value : 0;
      var receivedC = course && typeof course.received === "number" ? course.received : 0;
      var pendingC = Math.max(0, totalC - receivedC);

      var sumHtml =
        '<div class="drawer__section">'+
          '<header class="drawer__section-head">'+
            '<div><span class="micro">Recebido</span><div class="drawer__number">'+formatBRL(receivedC)+'</div></div>'+
            '<div><span class="micro">Falta</span><div class="drawer__number">'+formatBRL(pendingC)+'</div></div>'+
            '<div><span class="micro">Total</span><div class="drawer__number">'+formatBRL(totalC)+'</div></div>'+
          '</header>'+
          '<div class="micro">'+esc(entityName)+' · '+esc(courseName)+'</div>'+
        '</div>';

      var relInst = Array.isArray(detail.installments) ? detail.installments.slice() : [];
      relInst.sort(function(a,b){
        var da=safeDate(a.due_date), db=safeDate(b.due_date);
        if(da && db) return da - db;
        if(da && !db) return -1;
        if(!da && db) return 1;
        return (a.amount||0) - (b.amount||0);
      });
      var lines = relInst.map(function(item){
        var d=safeDate(item.due_date); var when=d? d.toLocaleDateString("pt-BR") : "-";
        var status=String(item.status||"").toUpperCase();
        return '<div class="info-line" data-tip="'+escAttr(tipFrom(item))+'"><div><strong>'+when+'</strong><span>'+esc(status)+'</span></div><span class="tag">'+formatBRL(item.amount)+'</span></div>';
      }).join('');
      var installmentsHtml = lines
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Parcelas</h4></header><div class="drawer__list">'+lines+'</div></div>'
        : '<div class="drawer__section"><div class="alert">Nenhuma parcela encontrada para este curso.</div></div>';

      dBody.innerHTML = sumHtml + installmentsHtml;

    } else if(kind==='month'){
      // lista do mês
      var byDay = {};
      norm.forEach(function(i){
        var d = safeDate(i.due_date), key = d ? d.toISOString().slice(0,10) : 'sem-data';
        (byDay[key]||(byDay[key]=[])).push(i);
      });
      var days = Object.keys(byDay).sort();
      var monthHtml = '<div class="drawer__section"><header class="drawer__section-head"><h4>Títulos do mês</h4></header><div class="drawer__list">'
        + days.map(function(day){
            var sum = byDay[day].reduce(function(a,b){return a+(b.amount||0);},0);
            return '<div class="info-line"><div><strong>'+ (day==='sem-data' ? '-' : new Date(day).toLocaleDateString('pt-BR')) +'</strong><span>'+byDay[day].length+' item(s)</span></div><span class="tag">'+formatBRL(sum)+'</span></div>';
          }).join('')
        + '</div></div>';
      dBody.innerHTML = summaryHtml + monthHtml;

    } else if(kind==='day'){
      // lista do dia
      var dayItems = norm.slice().sort(function(a,b){
        var da=safeDate(a.due_date), db=safeDate(b.due_date);
        if(da && db) return da - db;
        if(da && !db) return -1;
        if(!da && db) return 1;
        return (a.amount||0) - (b.amount||0);
      });
      var listHtml = '<div class="drawer__section"><header class="drawer__section-head"><h4>Cursos do dia</h4></header><div class="drawer__list">'
        + dayItems.map(function(s){
            var d = safeDate(s.due_date);
            var sub = [s.entity||'', s.course||''].filter(Boolean).join(' - ');
            return '<div class="info-line js-course" data-entity="'+escAttr(s.entity||'-')+'" data-course="'+escAttr(s.course||'-')+'" data-tip="'+escAttr(tipFrom(s))+'"><div><strong>'+(d?d.toLocaleDateString('pt-BR'):'-')+'</strong><span>'+esc(sub)+'</span></div><span class="tag">'+formatBRL(s.amount)+'</span></div>';
          }).join('')
        + '</div></div>';
      dBody.innerHTML = summaryHtml + listHtml;

    } else if(kind==='overdue'){
      // vencidos (top atrasos)
      var ranked=normFiltered.filter(function(i){return i.status==='overdue';})
                      .sort(function(a,b){return daysDiff(a.due_date)-daysDiff(b.due_date);});
      var insights=ranked.slice(0,20).map(function(i){
        var dd=daysDiff(i.due_date); var overdueDays=Math.abs(Math.min(0,dd));
        return '<div class="info-line is-danger js-course" data-entity="'+escAttr(i.entity||'-')+'" data-course="'+escAttr(i.course||'-')+'" data-tip="'+escAttr(tipFrom(i))+'"><div><strong>'+overdueDays+' dia(s)</strong><span>'+ esc(i.entity||'') +' - '+ esc(i.course||'') +'</span></div><span class="tag">'+formatBRL(i.amount)+'</span></div>';
      }).join('');
      dynamicHtml='<div class="drawer__section"><header class="drawer__section-head"><h4>Mais atrasados</h4></header><div class="drawer__list">'+(insights||'<div class="alert">Sem atrasos críticos.</div>')+'</div></div>';
      dBody.innerHTML = summaryHtml + dynamicHtml;

    } else {
      // A RECEBER (pendentes + futuros): entidades, agenda 6m e próximas datas
      // chips de prazo
      var chipsHtml = '<div class="drawer__section"><header class="drawer__section-head">'+
        '<h4>Prazo de recebimento</h4><span class="micro">Filtra pela distância do vencimento</span></header>'+
        '<div class="chips-line">'+
        ranges.map(function(r){ var active=(window.__dueActive===r.key)?' is-active':''; return '<button type="button" class="chip chip--toggle js-due" data-range="'+r.key+'">'+r.label+'</button>'; }).join('')+
        (window.__dueActive ? '<button type="button" class="chip js-due-clear">Limpar</button>' : '')+
        '</div></div>';

      // entidades (consolidado)
      var listEntities = Object.keys(byEntity).sort(function(a,b){ return a.localeCompare(b,'pt-BR'); }).map(function(name){
        var ent = byEntity[name];
        var courses = Object.keys(ent.courses).map(function(courseName){
          var c = ent.courses[courseName];
          var next = c.nextDue ? new Date(c.nextDue.getTime()) : null;
          return {
            name: courseName, sum: c.sum||0, overdue: c.overdue||0,
            nextDate: next, nextLabel: next ? next.toLocaleDateString('pt-BR') : ''
          };
        }).sort(function(a,b){
          if(a.nextDate && b.nextDate && a.nextDate.getTime()!==b.nextDate.getTime()) return a.nextDate - b.nextDate;
          if(a.nextDate && !b.nextDate) return -1;
          if(!a.nextDate && b.nextDate) return 1;
          return a.name.localeCompare(b.name,'pt-BR');
        });
        var courseTip = courses.map(function(c){
          var parts=[c.name]; if(c.nextLabel) parts.push('Prox.: '+c.nextLabel);
          parts.push('Previsto '+formatBRL(c.sum)); if(c.overdue>0) parts.push('Vencido '+formatBRL(c.overdue));
          return parts.join(' - ');
        }).join('\n');
        var earliest = courses.find(function(c){ return !!c.nextDate; });
        var subtitleParts = [courses.length+' curso(s)']; if(earliest){ subtitleParts.push('Prox. '+earliest.nextLabel); }
        var subtitleText = subtitleParts.join(' - ');
        var badges = '<span class="tag tag--accent">'+formatBRL(ent.sum)+'</span>'; if(ent.overdue>0){ badges += '<span class="tag tag--danger">'+formatBRL(ent.overdue)+' vencido</span>'; }
        return '<div class="info-line js-entity" data-entity="'+escAttr(name||'-')+'" data-tip="'+escAttr(courseTip)+'"><div><strong>'+esc(name||'-')+'</strong><span>'+esc(subtitleText)+'</span></div><div class="info-line__totals">'+badges+'</div></div>';
      }).join('');
      var entitiesHtml = listEntities
        ? '<div class="drawer__section"><header class="drawer__section-head"><h4>Entidades</h4><span class="micro">Visão geral consolidada</span></header><div class="drawer__list">'+listEntities+'</div></div>'
        : '<div class="drawer__section"><div class="alert">Nenhuma entidade com valores a receber.</div></div>';

      // agenda 6 meses (pagos excluídos)
      var monthsSorted=Object.keys(byMonth).filter(function(k){return k!=='sem-data';}).sort().slice(0,6).map(function(ym){return [ym,byMonth[ym]];});
      var forecastNote = '<div class="micro">Visão dos próximos 6 meses somando títulos <strong>A Receber</strong> e <strong>Vencidos</strong> por mês (pagos excluídos).</div>';
      var forecastHtml = monthsSorted.length
        ? ('<div class="drawer__section"><header class="drawer__section-head"><h4>Agenda de Caixa - 6 meses</h4></header>'
            + forecastNote
            + '<div class="forecast">'
            + monthsSorted.map(function(kv){
                var ym=kv[0], stat=kv[1], p=ym.split('-'); var y=p[0], m=Number(p[1]||'1')-1;
                var label=(m+1).toString().padStart(2,'0')+'/'+y.slice(-2);
                var pct=Math.min(100,Math.round(((stat.sum||0)/ (total||1))*100));
                var tip = formatBRL(stat.sum)+' · '+(stat.count||0)+' título(s)';
                return '<div class="forecast__item" data-ym="'+ym+'" data-tip="'+escAttr(tip)+'"><div class="forecast__bar" style="height:'+Math.max(12,pct)+'%"></div><span>'+label+'</span><strong>'+formatBRL(stat.sum)+'</strong><em class="micro">'+(stat.count||0)+' it.</em></div>';
              }).join('')
            + '</div></div>')
        : '';

      // próximas datas (primeiros 30)
      var upcoming = normFiltered.filter(function(i){ return i.status!=='paid'; })
                         .sort(function(a,b){
                           var da = safeDate(a.due_date), db=safeDate(b.due_date);
                           if(da && db) return da - db;
                           if(da && !db) return -1;
                           if(!da && db) return 1;
                           return (a.entity||'').localeCompare(b.entity||'', 'pt-BR');
                         }).slice(0,30);
      var upcomingHtml = upcoming.length
        ? ('<div class="drawer__section"><header class="drawer__section-head"><h4>Próximas datas</h4></header><div class="drawer__list">'
            + upcoming.map(function(s){
                var d = safeDate(s.due_date);
                var when = d ? d.toLocaleDateString('pt-BR') : '-';
                var subtitle = [s.entity||'', s.course||''].filter(Boolean).join(' - ');
                var dayKey = d ? d.toISOString().slice(0,10) : '';
                return '<div class="info-line js-day" data-day="'+dayKey+'" data-tip="'+escAttr(tipFrom(s))+'"><div><strong>'+when+'</strong><span>'+esc(subtitle)+'</span></div><span class="tag">'+formatBRL(s.amount)+'</span></div>';
              }).join('')
          + '</div></div>')
        : '';

      dBody.innerHTML = summaryHtml + chipsHtml + entitiesHtml + forecastHtml + upcomingHtml;

      // bind chips de prazo
      Array.prototype.forEach.call(dBody.querySelectorAll('.js-due'), function(b){
        b.addEventListener('click', function(){
          window.__dueActive = String(b.getAttribute('data-range')||'');
          openDrawer(currentState.title, currentState.items, currentState.kind, {restore:true});
        });
      });
      var clr = dBody.querySelector('.js-due-clear');
      if (clr) clr.addEventListener('click', function(){
        window.__dueActive = null;
        openDrawer(currentState.title, currentState.items, currentState.kind, {restore:true});
      });

      // clique no mês → detalhar
      Array.prototype.forEach.call(dBody.querySelectorAll('.forecast__item'), function(el){
        el.addEventListener('click', function(){
          var ym = el.getAttribute('data-ym') || '';
          var itemsM = (datasetAll||[]).filter(function(i){
            return i && i.status !== 'paid' && (String(i.due_date||'').slice(0,7) === ym);
          });
          openDrawer('Mês '+ym, itemsM, 'month', {push:true});
        });
      });

      // clique na data → cursos do dia
      Array.prototype.forEach.call(dBody.querySelectorAll('.js-day'), function(el){
        el.addEventListener('click', function(){
          var day = el.getAttribute('data-day') || '';
          var itemsD = norm.filter(function(i){
            var d=safeDate(i.due_date); return d && d.toISOString().slice(0,10)===day;
          });
          openDrawer('Dia '+day, itemsD, 'day', {push:true});
        });
      });
    }

    if(!drawer.classList.contains('drawer--open')){
      drawer.classList.add('drawer--open');
      body.classList.add('no-scroll');
    }
    drawer.classList.add('drawer--full');

    // estado atual
    currentState = { title: title, items: items, kind: kind||'pending' };
  }

  // Abridores (KPIs + entidades/curso da lista)
  function bindOpeners(){
    // KPIs usam SEMPRE os itens filtrados
    var kpies = document.querySelectorAll('.kpi[data-open]');
    for(var i=0;i<kpies.length;i++){
      var el = kpies[i];
      function handle(kind){
        var raw = Array.isArray(filteredInstallments) ? filteredInstallments : [];
        var items = raw.filter(function(i){
          if(kind==='pending') return i.status!=='paid';
          return i.status===kind;
        });
        var ttl = kind==='paid'?'Recebidos':(kind==='overdue'?'Vencidos':'A Receber');
        openDrawer(ttl, items, kind, {push:false});
      }
      el.addEventListener('click', function(){ handle(this.getAttribute('data-open')); });
      el.addEventListener('keydown', function(e){ if(e.key==='Enter' || e.key===' ') { e.preventDefault(); handle(this.getAttribute('data-open')); } });
    }

    // entidade/curso (cards/lista)
    document.addEventListener('click', function (e) {
      var elEnt = e.target.closest('.entity-action.js-entity, .card.js-entity, .info-line.js-entity, .entity.js-entity');
      if (elEnt) {
        var name = elEnt.getAttribute('data-entity') || elEnt.textContent.trim();
        var detail = collectEntityDetail(name);
        openDrawer(name, detail, 'entity', {push:true});
        return;
      }
      var elCourse = e.target.closest('.js-course');
      if (elCourse) {
        var en = elCourse.getAttribute('data-entity') || '';
        var cn = elCourse.getAttribute('data-course') || '';
        var detail = collectCourseDetail(en, cn);
        openDrawer(cn || 'Curso', detail, 'course', {push:true});
      }
    });
  }
  bindOpeners();

  // ESC fecha modal/drawer
  window.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      if(modal && modal.classList.contains('modal--open')){ modal.classList.remove('modal--open'); body.classList.remove('no-scroll'); }
      closeDrawer();
    }
  });
})();
</script>
</body>
</html>
