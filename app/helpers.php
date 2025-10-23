<?php
// app/helpers.php

/* =========================
   CONFIG
========================= */
function cfg($key){
  static $cfg=null;
  if(!$cfg){ $cfg = require __DIR__.'/config.php'; }
  return $cfg[$key] ?? null;
}

/* =========================
   UTF-8 / NORMALIZAÇÃO
========================= */
function _u($s){
  if ($s===null) return '';
  $enc = mb_detect_encoding($s, ['UTF-8','ISO-8859-1','Windows-1252'], true);
  return $enc && $enc!=='UTF-8' ? mb_convert_encoding($s,'UTF-8',$enc) : (string)$s;
}

function remove_accents($s){
  $s = _u($s);
  $s = str_replace(['º','ª'], '', $s);
  $r = @iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s);
  return $r !== false ? $r : $s;
}

function squash_spaces($s){
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', _u($s)); // NBSP, ZWSP
  $s = preg_replace('/\s+/u', ' ', $s);
  return trim($s);
}
/** Normaliza classificação para uma CHAVE canônica sem acento/espacos/hífens */
function normalize_class_key($s){
  $s = squash_spaces($s);
  $s = remove_accents(mb_strtolower($s, 'UTF-8'));

  // normaliza separadores comuns
  $s = str_replace(['-', '_'], ' ', $s);
  $s = preg_replace('/\s+/u', ' ', $s);
  $s = trim($s);

  // unifica variantes
  $map = [
    'in company'       => 'incompany',
    'incompany'        => 'incompany',
    'curso aberto'     => 'curso aberto',
    'ead'              => 'ead',
    'consultoria'      => 'consultoria',
    'pro labore'       => 'pro-labore',
    'pro-labore'       => 'pro-labore',
    'simples repasse'  => 'simples repasse',
  ];
  if ($s==='') return 'sem classificacao';
  return $map[$s] ?? $s; // deixa passar outros nomes, mas sempre “limpos”
}

/** Rótulo “bonito” a partir da chave canônica */
function class_label_from_key($key){
  $labels = [
    'consultoria'       => 'Consultoria',
    'incompany'         => 'Incompany',
    'curso aberto'      => 'Curso Aberto',
    'ead'               => 'EAD',
    'pro-labore'        => 'Pró-labore',
    'simples repasse'   => 'Simples Repasse',
    'sem classificacao' => 'Sem Classificação',
  ];
  return $labels[$key] ?? mb_convert_case($key, MB_CASE_TITLE, 'UTF-8');
}

function normalize_header_key($s){
  $s = _u($s);
  $s = mb_strtolower(trim($s), 'UTF-8');
  $s = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $s); // nbsp/zwsp
  $s = preg_replace('/\s+/', ' ', $s);
  $s = remove_accents($s);

  // mapeamentos amigáveis / prováveis
  $map = [
    // entidade
    'órgão ou entidade' => 'orgao_entidade',
    'orgao ou entidade' => 'orgao_entidade',
    'orgao entidade'    => 'orgao_entidade',
    'órgão'             => 'orgao_entidade',
    'orgao'             => 'orgao_entidade',
    'entidade'          => 'orgao_entidade',

    // dinheiro
    'valor honorario'   => 'valor_honorario',
    'vlr hon.'          => 'valor_honorario',
    'vlr. hon.'         => 'valor_honorario',
    'pagamento honorario'=> 'pagamento_honorario',
    'pagamento hon.'    => 'pagamento_honorario',
    'pgto hon.'         => 'pagamento_honorario',
    'pgto hon'          => 'pagamento_honorario',
    'valor a receber'   => 'valor_a_receber',
    'a receber'         => 'valor_a_receber',
    'valor_receber'     => 'valor_a_receber',

    // datas
    'vencimento'        => 'vencimento',
    'classificacao'     => 'classificacao',
    'curso'             => 'curso',

    // datas de início/fim (variações)
    'data inicio'       => 'data_inicio',
    'data início'       => 'data_inicio',
    'data_inicial'      => 'data_inicio',
    'data_inicio'       => 'data_inicio',
    'datainicio'        => 'data_inicio',

    'data fim'          => 'data_fim',
    'data termino'      => 'data_fim',
    'data término'      => 'data_fim',
    'data_final'        => 'data_fim',
    'data_fim'          => 'data_fim',
    'datafim'           => 'data_fim',

    // carga horária
    'c.h'               => 'ch',
    'ch'                => 'ch',
  ];
  if (isset($map[$s])) return $map[$s];

  // fallback: snake_case
  $s = preg_replace('/[^a-z0-9]+/','_', $s);
  return trim($s, '_');
}

/* =========================
   CSV
========================= */
function csv_get_assoc($url){
  $ctx = stream_context_create(['http'=>['timeout'=>25,'header'=>"User-Agent: PHP\r\n"]]);
  $fh = @fopen($url, 'r', false, $ctx);
  if(!$fh) return [];

  $headerRow = fgetcsv($fh, 0, ',', '"', "\\");
  if($headerRow === false){ fclose($fh); return []; }

  // detecta ; se veio tudo numa coluna
  if (count($headerRow) === 1) {
    rewind($fh);
    $headerRow = fgetcsv($fh, 0, ';', '"', "\\");
    $sep = ';';
  } else {
    $sep = ',';
  }

  $header = array_map(fn($h)=> normalize_header_key($h), $headerRow);

  $rows=[];
  while(($data = fgetcsv($fh, 0, $sep, '"', "\\")) !== false){
    if(count($data)===1 && ($data[0]===null || trim($data[0])==='')) continue;
    $row=[];
    foreach($header as $i=>$h){
      $row[$h] = _u($data[$i] ?? '');
    }
    $rows[]=$row;
  }
  fclose($fh);
  return $rows;
}

/* =========================
   NÚMEROS / DINHEIRO / DATAS
========================= */
function money_to_float($s){
  if($s===null) return null;
  $s = _u($s);
  $s = trim($s);
  if($s==='') return null;

  $s = mb_strtolower($s,'UTF-8');
  $s = str_replace(['r$',' '],'',$s);

  if(str_contains($s,'mil')){
    $n = str_replace(['mil','.',' '],'', $s);
    $n = str_replace(',','.', $n);
    return is_numeric($n) ? ((float)$n*1000) : null;
  }
  if(str_ends_with($s,'k')){
    $n = str_replace(',','.', substr($s,0,-1));
    return is_numeric($n) ? ((float)$n*1000) : null;
  }
  $n = str_replace('.','', $s);
  $n = str_replace(',','.', $n);
  return is_numeric($n) ? (float)$n : null;
}

/**
 * Converte vários formatos de data para ISO (YYYY-MM-DD).
 * No front, SEMPRE use dmy() para exibir em BR.
 */function parse_date_any($s){
  $s = trim(_u($s));
  if($s==='') return null;

  // dd/mm/yyyy [hh[:mm[:ss]]]
  if(preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})(?:[\/\-\.](\d{2,4}))?(?:\s+(\d{1,2})(?::(\d{1,2})(?::(\d{1,2}))?)?)?$/', $s, $m)){
    $d  = (int)$m[1];
    $mo = (int)$m[2];
    $y  = isset($m[3]) ? (int)$m[3] : (int)date('Y');
    if($y<100) $y += 2000;
    if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d', $y,$mo,$d);
    return null;
  }

  // yyyy-mm-dd (ou com / .)
  if(preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})/', $s, $m)){
    $y=(int)$m[1]; $mo=(int)$m[2]; $d=(int)$m[3];
    if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d', $y,$mo,$d);
    return null;
  }

  // último recurso — evita timezone dance
  $ts = @strtotime($s);
  return $ts ? date('Y-m-d', $ts) : null;
}


/** Exibe ISO em formato BR */
function dmy($iso){
  if(!$iso) return '-';
  $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $iso);
  return $dt ? $dt->format('d/m/Y') : '-';
}

function brl($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }

/* =========================
   MAPEAMENTO DE LINHA
========================= */
function map_row($r){
  $get = function(array $cands) use ($r){
    foreach($cands as $c){
      $k = normalize_header_key($c);
      foreach($r as $kk=>$vv){ if($kk===$k) return trim(_u($vv)); }
    }
    return '';
  };

  // pagamentos 1..6 + datas
   $payAmts=[]; $payDates=[];
  for($i=1;$i<=6;$i++){
    $payAmts[$i]  = $get(["pagamento $i","pgmto $i","pgto $i","pgt $i","pag $i"]);
    $payDates[$i] = $get(["data $i","dt $i"]);
  }

// campos base
  $rawClass   = $get(['classificacao']);
$classificacao = squash_spaces($rawClass); // mantém a versão “humana”


  $dateStart = parse_date_any($get([
    'data inicio','data início','data_inicial','data_inicio','datainicio'
  ]));
  $dateEnd   = parse_date_any($get([
    'data fim','data término','data termino','data_final','data_fim','datafim'
  ]));
  $venc      = parse_date_any($get(['vencimento']));

  // regra: Consultoria não tem datas
  if ($classificacao === 'consultoria') {
    $dateStart = null;
    $dateEnd   = null;
    $venc      = null;
  }

  return [
    'entity'          => $get(['orgao_entidade','órgão ou entidade','orgao ou entidade','entidade']),
    'course'          => $get(['curso']),
    'classificacao'   => $classificacao,
    'ch'              => $get(['ch','c.h']),
    'date_start'      => $dateStart,
    'date_end'        => $dateEnd,
    'honorarium'      => money_to_float($get(['valor honorario','vlr hon.','vlr. hon.'])),
    'vencimento'      => $venc,
    'valor_a_receber' => money_to_float($get(['valor a receber','a receber','valor_receber'])),
    'payments'        => array_map(function($i) use ($payAmts,$payDates){
                           return [
                             'amount' => money_to_float($payAmts[$i] ?? null) ?? 0.0,
                             'date'   => parse_date_any($payDates[$i] ?? null)
                           ];
                         }, range(1,6)),
  ];
}

/**
 * Normaliza valores financeiros garantindo coerência entre total, recebido e pendente.
 */
function normalize_dataset(array $data): array{
  $totalReceivable = 0.0;
  $totalReceived   = 0.0;

  if(isset($data['entities']) && is_array($data['entities'])){
    foreach($data['entities'] as &$entity){
      $entityTotal    = 0.0;
      $entityReceived = 0.0;

      if(isset($entity['items']) && is_array($entity['items'])){
        foreach($entity['items'] as &$item){
          $value    = max(0.0, (float)($item['value'] ?? 0));
          $received = max(0.0, (float)($item['received'] ?? 0));
          $pending  = max(0.0, (float)($item['pending'] ?? 0));

          if($value <= 0 && ($received > 0 || $pending > 0)){
            $value = $received + $pending;
          }
          if($value > 0 && $received > $value){
            $value = $received;
          }
          $pending = max(0.0, $value - $received);

          $value    = round($value, 2);
          $received = round($received, 2);
          $pending  = round($pending, 2);

          $item['value']    = $value;
          $item['received'] = $received;
          $item['pending']  = $pending;

          $entityTotal    += $value;
          $entityReceived += $received;
        }
        unset($item);
      }

      $entityTotal    = round($entityTotal, 2);
      $entityReceived = round($entityReceived, 2);
      $entityPending  = max(0.0, $entityTotal - $entityReceived);

      $entity['total']    = $entityTotal;
      $entity['received'] = $entityReceived;

      $totalReceivable += $entityPending;
      $totalReceived   += $entityReceived;
    }
    unset($entity);
  }

  if(!isset($data['kpis']) || !is_array($data['kpis'])){
    $data['kpis'] = [];
  }
  $data['kpis']['receivable'] = round($totalReceivable, 2);
  $data['kpis']['received']   = round($totalReceived, 2);

  $totalOverdue = 0.0;
  if(isset($data['installments']) && is_array($data['installments'])){
    foreach($data['installments'] as $inst){
      if(($inst['status'] ?? '') === 'overdue'){
        $totalOverdue += (float)($inst['amount'] ?? 0);
      }
    }
  }
  $data['kpis']['overdue'] = round($totalOverdue, 2);

  return $data;
}

/* =========================
   DATASET + CACHE
========================= */
function get_data($forceRefresh=false){
  $cacheFile = __DIR__.'/../cache/data.json';

  if(!$forceRefresh && is_file($cacheFile)){
    $j = json_decode(@file_get_contents($cacheFile), true);
    if($j && isset($j['created_at']) && (time() - $j['created_at'] < 60*10)){
      return normalize_dataset($j);
    }
  }

  // 1) lê planilha
  $raw = csv_get_assoc(cfg('CSV_ENGAGEMENTS'));

  // 2) normaliza
  $rows = array_map('map_row', $raw);

  // 3) monta estruturas
  $todayIso = date('Y-m-d');

  $installments = [];  // parcelas p/ telas (paid = cada pagamento; pending = 1 linha com "valor a receber")
  $sumReceivable = 0.0;
  $sumReceived   = 0.0;
  $sumOverdue    = 0.0;
  $groupsMap     = [];

  foreach($rows as $e){
    // ignora linhas vazias
    if(trim($e['entity'])==='' && trim($e['course'])==='') continue;

    // totais de pagamentos recebidos (até 6) — NOVA LÓGICA
    $received   = 0.0;
    $paymentsUI = [];

    // colete primeiro as parcelas pagas (não zeradas) preservando o índice 1..6
    $paidList = [];
    foreach($e['payments'] as $idx=>$p){
      $i    = $idx + 1; // 1..6
      $amt  = (float)($p['amount'] ?? 0);
      $date = $p['date'] ?: null; // ISO
      if($amt > 0){
        $paidList[] = ['i'=>$i, 'amount'=>$amt, 'date'=>$date];
      }
    }
    $instTotal = count($paidList);

    // some e popule installments (status=paid) usando a **data do pagamento**
    foreach($paidList as $pp){
      $received += $pp['amount'];

      $installments[] = [
        'entity'     => $e['entity'] ?: '-',
        'course'     => $e['course'] ?: '-',
        'amount'     => $pp['amount'],
        'due_date'   => $pp['date'] ?: null,   // <- data do pagamento
        'status'     => 'paid',
        'inst_no'    => $pp['i'],              // 1..6
        'inst_total' => $instTotal,
      ];

      $paymentsUI[] = [
        'amount'     => $pp['amount'],
        'date'       => $pp['date'],
        'inst_no'    => $pp['i'],
        'inst_total' => $instTotal
      ];
    }

    // valor a receber (coluna nova; se vier vazio, fallback = honorarium - received)
    $hon  = (float)($e['honorarium'] ?? 0);
    $arec = $e['valor_a_receber'];
    if($arec === null){
      $arec = max(0.0, $hon - $received);
    } else {
      $arec = (float)$arec;
    }
    $pendingNow = max(0.0, $arec);

    // KPIs
    $sumReceivable += $pendingNow; // A Receber
    $sumReceived   += $received;   // Recebidos

    // vencido = "a receber" cujo vencimento passou (consultoria não conta)
    $isConsultoria = ($e['classificacao'] === 'consultoria');
    $vencIso       = $isConsultoria ? null : ($e['vencimento'] ?: null);
    if($pendingNow > 0 && $vencIso && $vencIso < $todayIso){
      $sumOverdue += $pendingNow;
    }// parcela "pending" (o que falta receber) — agora com X/Y
if ($pendingNow > 0) {
  $paidCount = count($paidList);          // quantas já foram pagas
  $nextNo    = $paidCount + 1;            // próxima parcela (X)
  // melhor esforço para "total de parcelas (Y)":
  // se alguma paga já veio com inst_total, pegue o maior; senão assuma Y = X
  $knownTotal = 0;
  foreach ($paidList as $pp) {
    if (!empty($pp['inst_total'])) $knownTotal = max($knownTotal, (int)$pp['inst_total']);
  }
  $seriesTotal = max($knownTotal, $nextNo);

  $installments[] = [
    'entity'     => $e['entity'] ?: '-',
    'course'     => $e['course'] ?: '-',
    'amount'     => round($pendingNow, 2),
    'due_date'   => $vencIso, // consultoria -> null
    'status'     => ($vencIso && $vencIso < $todayIso) ? 'overdue' : 'pending',
    'inst_no'    => $nextNo,
    'inst_total' => $seriesTotal,
  ];
}

    // ===== AGRUPAMENTO: por CLASSIFICAÇÃO (não por entidade) =====
$classKey  = normalize_class_key($e['classificacao'] ?? '');
$className = class_label_from_key($classKey);

if (!isset($groupsMap[$classKey])) {
  $groupsMap[$classKey] = [
    'key'      => $classKey,
    'name'     => $className,
    'items'    => [],
    'total'    => 0.0,
    'received' => 0.0,
  ];
}


    // range mostrado na lista (sempre BR via dmy())
    $range = '-';
    if ($e['date_start'] || $e['date_end']) {
      $range = ($e['date_start'] ? dmy($e['date_start']) : '—').' — '.($e['date_end'] ? dmy($e['date_end']) : '—');
    }

    $totalValue = $hon > 0 ? $hon : ($received + $pendingNow);
    if ($totalValue <= 0 && $received > 0) $totalValue = $received;
    if ($totalValue > 0 && $received > $totalValue) $totalValue = $received;
    $pendingDisplay = max(0.0, $totalValue - $received);

    $item = [
      'entity'     => $e['entity'] ?: '-',        // <- mantém a entidade dentro do item (útil para mostrar no card)
      'course'     => $e['course'] ?: '-',
      'ch'         => trim($e['ch']) !== '' ? $e['ch'] : '-',
      'range'      => $range,
      'date_start' => $e['date_start'],
      'date_end'   => $e['date_end'],
      'value'      => round($totalValue, 2),
      'received'   => round($received, 2),
      'pending'    => round($pendingDisplay, 2),
      'vencimento' => $vencIso,                   // pode ser null em Consultoria
      'class'      => $classKey,                  // chave da classificação
      'payments'   => $paymentsUI,
    ];

    $groupsMap[$classKey]['items'][] = $item;
    $groupsMap[$classKey]['total']   += $item['value'];
    $groupsMap[$classKey]['received']+= $item['received'];
  }

  // ordenar grupos (ordem personalizada)
  $entities = array_values($groupsMap);
  $order = [
    'consultoria'      => 1,
    'incompany'        => 2,
    'curso aberto'     => 3,
    'ead'              => 4,
    'pró-labore'       => 5,
    'pro-labore'       => 5,
    'simples repasse'  => 6,
  ];
  usort($entities, function($a, $b) use ($order){
    $ka = $order[$a['key']] ?? 999;
    $kb = $order[$b['key']] ?? 999;
    if ($ka === $kb) return strcmp($a['name'], $b['name']);
    return $ka <=> $kb;
  });

  $data = [
    'created_at'   => time(),
    'kpis' => [
      'receivable' => $sumReceivable,
      'received'   => $sumReceived,
      'overdue'    => $sumOverdue,
    ],
    'entities'     => $entities,   // mantém o mesmo nome esperado pela UI
    'installments' => $installments,
  ];

  $data = normalize_dataset($data);

  if(!is_dir(dirname($cacheFile))) @mkdir(dirname($cacheFile), 0777, true);
  @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));

  return $data;
}

/* =========================
   SETTINGS (tema etc.)
========================= */
function _settings_path(){ return __DIR__ . '/../cache/settings.json'; }

function load_settings(){
  $defaults = [
    'theme'         => 'jml',
    'accent_hue'    => 160,
    'glass'         => true,
    'density'       => 'normal',
    'bg_anim_speed' => 40,
  ];
  $p = _settings_path();
  if (is_file($p)) {
    $s = json_decode(@file_get_contents($p), true);
    if (is_array($s)) return array_merge($defaults, $s);
  }
  return $defaults;
}

function save_settings($in){
  $s = load_settings();
  $s['theme']         = in_array($in['theme'] ?? 'jml', ['jml','dark','light','mono']) ? $in['theme'] : 'jml';
  $s['accent_hue']    = max(0, min(360, (int)($in['accent_hue'] ?? 160)));
  $s['glass']         = !empty($in['glass']);
  $s['density']       = in_array($in['density'] ?? 'normal', ['compact','normal','spacious']) ? $in['density'] : 'normal';
  $s['bg_anim_speed'] = max(10, min(120, (int)($in['bg_anim_speed'] ?? 40)));

  if (!is_dir(dirname(_settings_path()))) @mkdir(dirname(_settings_path()), 0777, true);
  @file_put_contents(_settings_path(), json_encode($s, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  return $s;
}

