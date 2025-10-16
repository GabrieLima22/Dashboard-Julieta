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
 */
function parse_date_any($s){
  $s = trim(_u($s));
  if($s==='') return null;

  // dd/mm/yyyy, dd/mm/yy, dd-mm-yyyy, etc
  if(preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})(?:[\/\-\.](\d{2,4}))?$/', $s, $m)){
    $d=(int)$m[1]; $mo=(int)$m[2]; $y=isset($m[3])?(int)$m[3]:(int)date('Y');
    if($y<100) $y += 2000;
    if(checkdate($mo,$d,$y)) return sprintf('%04d-%02d-%02d',$y,$mo,$d);
    return null;
  }
  // yyyy-mm-dd, yyyy/mm/dd, textos que o strtotime aceita
  $ts = strtotime($s);
  return $ts ? date('Y-m-d',$ts) : null;
}

/** Exibe ISO em formato BR */
function dmy($iso){
  if(!$iso) return '-';
  $ts = strtotime($iso);
  return $ts ? date('d/m/Y',$ts) : '-';
}

function brl($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }

/* =========================
   MAPEAMENTO DE LINHA
========================= */
function map_row($r){
  // acesso flexível por nomes prováveis
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
  $classificacao = mb_strtolower(trim($get(['classificacao'])), 'UTF-8');

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

/* =========================
   DATASET + CACHE
========================= */
function get_data($forceRefresh=false){
  $cacheFile = __DIR__.'/../cache/data.json';

  if(!$forceRefresh && is_file($cacheFile)){
    $j = json_decode(@file_get_contents($cacheFile), true);
    if($j && isset($j['created_at']) && (time() - $j['created_at'] < 60*10)){
      return $j;
    }
  }

  // 1) lê planilha
  $raw = csv_get_assoc(cfg('CSV_ENGAGEMENTS'));

  // 2) normaliza
  $rows = array_map('map_row', $raw);

  // 3) monta estruturas
  $todayIso = date('Y-m-d');

  $entitiesMap = [];   // agrupamento pra UI
  $installments = [];  // parcelas p/ telas (paid = cada pagamento; pending = 1 linha com "valor a receber")
  $sumReceivable = 0.0;
  $sumReceived   = 0.0;
  $sumOverdue    = 0.0;

  foreach($rows as $e){
    // ignora linhas vazias
    if(trim($e['entity'])==='' && trim($e['course'])==='') continue;

    // totais de pagamentos recebidos (até 6)
    $received = 0.0; $paymentsUI=[];
    foreach($e['payments'] as $p){
      $amt = (float)($p['amount'] ?? 0);
      if($amt > 0){
        $received += $amt;

        // parcela "paid" (com ou sem data)
        $installments[] = [
          'entity'   => $e['entity'] ?: '-',
          'course'   => $e['course'] ?: '-',
          'amount'   => $amt,
          'due_date' => $p['date'] ?: null,  // usa a data do pagamento quando houver
          'status'   => 'paid',
        ];
        $paymentsUI[] = ['amount'=>$amt, 'date'=>$p['date']];
      }
    }

    // valor a receber (coluna nova; se vier vazio, fallback = honorarium - received)
    $hon  = (float)($e['honorarium'] ?? 0);
    $arec = $e['valor_a_receber'];
    if($arec === null){
      $arec = max(0.0, $hon - $received);
    } else {
      $arec = (float)$arec;
    }

    // KPIs
    $sumReceivable += $arec;     // A Receber
    $sumReceived   += $received; // Recebidos

    // vencido = "a receber" cujo vencimento passou (consultoria não conta)
    $isConsultoria = ($e['classificacao'] === 'consultoria');
    $vencIso       = $isConsultoria ? null : ($e['vencimento'] ?: null);
    if($arec > 0 && $vencIso && $vencIso < $todayIso){
      $sumOverdue += $arec;
    }

    // parcela "pending" (o que falta receber)
    if($arec > 0){
      $installments[] = [
        'entity'   => $e['entity'] ?: '-',
        'course'   => $e['course'] ?: '-',
        'amount'   => $arec,
        'due_date' => $vencIso, // consultoria -> null
        'status'   => ($vencIso && $vencIso < $todayIso) ? 'overdue' : 'pending',
      ];
    }

    // agrupa por entidade → cursos
    $entKey = $e['entity'] ?: '-';
    if(!isset($entitiesMap[$entKey])){
      $entitiesMap[$entKey] = ['name'=>$entKey, 'items'=>[], 'total'=>0.0, 'received'=>0.0];
    }

    // range mostrado na lista (sempre BR via dmy())
    $range = '-';
    if($e['date_start'] || $e['date_end']){
      $range = ($e['date_start'] ? dmy($e['date_start']) : '—').' — '.($e['date_end'] ? dmy($e['date_end']) : '—');
    }

    $item = [
      'course'     => $e['course'] ?: '-',
      'ch'         => trim($e['ch']) !== '' ? $e['ch'] : '-',
      'range'      => $range,
      'date_start' => $e['date_start'],           // ISO bruto p/ detalhes
      'date_end'   => $e['date_end'],             // ISO bruto p/ detalhes
      'value'      => $hon,
      'received'   => $received,
      'pending'    => $arec,
      'vencimento' => $vencIso,                   // pode ser null em Consultoria
      'class'      => $e['classificacao'] ?: '',
      'payments'   => $paymentsUI,
    ];
    $entitiesMap[$entKey]['items'][] = $item;
    $entitiesMap[$entKey]['total']   += $item['value'];
    $entitiesMap[$entKey]['received']+= $item['received'];
  }

  // ordena entidades por nome
  $entities = array_values($entitiesMap);
  usort($entities, fn($a,$b)=> strcmp($a['name'],$b['name']));

  $data = [
    'created_at'   => time(),
    'kpis' => [
      'receivable' => $sumReceivable,
      'received'   => $sumReceived,
      'overdue'    => $sumOverdue,
    ],
    'entities'     => $entities,
    'installments' => $installments,
  ];

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
