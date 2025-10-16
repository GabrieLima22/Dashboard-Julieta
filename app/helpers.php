<?php
// app/helpers.php

function cfg($key){ static $cfg=null; if(!$cfg){ $cfg = require __DIR__.'/config.php'; } return $cfg[$key] ?? null; }

function csv_get_assoc($url){
  $ctx = stream_context_create(['http'=>['timeout'=>15,'header'=>"User-Agent: PHP\r\n"]]);
  $fh = @fopen($url, 'r', false, $ctx);
  if(!$fh) return [];
  $header = null; $rows=[];
  while(($data = fgetcsv($fh, 0, ',', '"', "\\")) !== false){
    if(!$header){ $header = array_map('trim',$data); continue; }
    if(count($data) == 1 && $data[0]===null) continue;
    $row = [];
    foreach($header as $i=>$h){ $row[mb_strtolower(trim($h))] = $data[$i] ?? ''; }
    $rows[] = $row;
  }
  fclose($fh);
  return $rows;
}

function money_to_float($s){
  if($s===null) return null;
  $s = trim((string)$s);
  if($s==='') return null;
  $s = mb_strtolower($s,'UTF-8');
  $s = str_replace(['r$',' '],'',$s);
  // "12 mil" -> 12000
  if(str_contains($s,'mil')){
    $n = trim(str_replace('mil','',$s));
    $n = str_replace(['.','. '],'',$n);
    $n = str_replace(',','.', $n);
    if(is_numeric($n)) return (float)$n*1000;
  }
  // "3k" -> 3000
  if(str_ends_with($s,'k')){
    $n = str_replace(',','.', substr($s,0,-1));
    if(is_numeric($n)) return (float)$n*1000;
  }
  $n = str_replace('.','', $s);
  $n = str_replace(',','.', $n);
  return is_numeric($n) ? (float)$n : null;
}

// Parse â€œVENCIMENTOâ€ em texto livre
function parse_vencimentos($text){
  $s = mb_strtolower((string)$text,'UTF-8');

  // 1) Datas dd/mm[/yyyy]
  preg_match_all('/\b(\d{1,2})[\/\-\.](\d{1,2})(?:[\/\-\.](\d{2,4}))?\b/u', $s, $dates, PREG_OFFSET_CAPTURE);
  $dateHits=[];
  foreach($dates[0] as $i=>$hit){
    [$full,$pos] = $hit;
    $d=(int)$dates[1][$i][0]; $m=(int)$dates[2][$i][0];
    $y = $dates[3][$i][0] ?? date('Y');
    if((int)$y < 100) $y = 2000 + (int)$y;
    $dateHits[] = ['pos'=>$pos,'date'=>sprintf('%04d-%02d-%02d',$y,$m,$d)];
  }

  // 2) Valores
  preg_match_all('/(?:r\$\s*)?(\d{1,3}(?:\.\d{3})*(?:,\d{2})?|\d{3,}(?:,\d{2})?|\d+[.,]?\d*\s*k|\d+\s*mil)/u', $s, $vals, PREG_OFFSET_CAPTURE);

  $items=[];
  foreach($vals[0] as $vhit){
    [$tok,$vpos] = $vhit;
    $raw = trim(mb_strtolower($tok,'UTF-8'));
    // normalizar
    $raw = str_replace('r$','',$raw);
    $raw = preg_replace('/\s+/',' ', $raw);

    $amount = null;
    // 3k / 12 mil
    if(str_ends_with($raw,'k')){
      $amount = (float)str_replace(',','.', substr($raw,0,-1))*1000;
    } elseif(str_contains($raw,'mil')){
      $amount = (float)str_replace(',','.', str_replace('mil','',$raw))*1000;
    } else {
      $n = str_replace('.','', $raw);
      $n = str_replace(',','.', $n);
      if(is_numeric($n)) $amount = (float)$n;
    }

    // ðŸ”’ evita pegar "11" de 11/04 como dinheiro
    if($amount !== null && $amount < 100) continue;

    // achar a data mais prÃ³xima desse valor
    $nearest = null; $mindist = 1e9;
    foreach($dateHits as $d){
      $dist = abs($vpos - $d['pos']);
      if($dist < $mindist){ $mindist = $dist; $nearest = $d['date']; }
    }
    $paid = preg_match('/\b(pago|paga|paguei|recebido|creditado|pix|dep)/u', $s) ? true : false;

    if($amount !== null && $nearest){
      $items[] = ['due_date'=>$nearest, 'amount'=>$amount, 'paid'=>$paid];
    }
  }
  return $items;
}

// Normaliza linha de â€œJulieta_exportâ€
function map_engagement_row($r){
  // tenta encontrar colunas independentemente de maiÃºsculas/acentos
  $get = function($keys) use ($r){
    foreach($keys as $k){
      $k = mb_strtolower($k,'UTF-8');
      foreach($r as $kk=>$vv){
        if(trim($kk)===$k) return trim((string)$vv);
      }
    }
    return '';
  };

  return [
    'date'         => $get(['data']),
    'ch'           => $get(['ch','c.h']),
    'course'       => $get(['curso']),
    'entity'       => $get(['orgÃ£o ou entidade','orgao ou entidade','entidade','Ã³rgÃ£o ou entidade']),
    'honorarium'   => money_to_float($get(['vlr hon.','vlr. hon.','valor honorÃ¡rio','valor honorario'])),
    'payments_raw' => $get(['vencimento','pgto hon.','pgto hon','pagamento honorÃ¡rio','pagamento honorario']),
  ];
}

// ConstrÃ³i o dataset (com cache)
function get_data($forceRefresh=false){
  $cacheFile = __DIR__.'/../cache/data.json';
  if(!$forceRefresh && file_exists($cacheFile)){
    $j = json_decode(file_get_contents($cacheFile), true);
    if($j && isset($j['created_at']) && (time() - $j['created_at'] < 60*15)){ // 15 min
      return $j;
    }
  }

  $rawEng = csv_get_assoc(cfg('CSV_ENGAGEMENTS'));
  $engs   = array_map('map_engagement_row', $rawEng);

  // parse vencimentos
  $installments = [];
  foreach($engs as $e){
    foreach(parse_vencimentos($e['payments_raw']) as $it){
      $installments[] = [
        'entity'   => $e['entity'],
        'course'   => $e['course'],
        'hon'      => $e['honorarium'] ?? 0,
        'due_date' => $it['due_date'],
        'amount'   => $it['amount'],
        'status'   => $it['paid'] ? 'paid' : ((strtotime($it['due_date']) < strtotime('today')) ? 'overdue' : 'pending'),
      ];
    }
  }

  // Totais
  $totals = [
    'receivable' => array_sum(array_map(fn($i)=> in_array($i['status'],['pending','overdue']) ? $i['amount'] : 0, $installments)),
    'received'   => array_sum(array_map(fn($i)=> $i['status']==='paid' ? $i['amount'] : 0, $installments)),
    'overdue'    => array_sum(array_map(fn($i)=> $i['status']==='overdue' ? $i['amount'] : 0, $installments)),
  ];

  // PrÃ³ximos 7 dias
  $upcoming = array_values(array_filter($installments, function($i){
    if($i['status']!=='pending') return false;
    $d = strtotime($i['due_date']);
    return $d >= strtotime('today') && $d <= strtotime('+7 days');
  }));
  usort($upcoming, fn($a,$b)=> strcmp($a['due_date'],$b['due_date']));

  // Por entidade (cada linha do CSV representa um curso)
  $group = [];
  foreach($engs as $e){
    $key = $e['entity'] ?: '-';
    if(!isset($group[$key])) $group[$key] = ['name'=>$key,'items'=>[],'total'=>0,'received'=>0];
    $item = [
      'range'    => $e['date'],
      'ch'       => $e['ch'],
      'course'   => $e['course'],
      'value'    => $e['honorarium'] ?? 0,
      'received' => 0,
      'pending'  => 0,
    ];
    $rec = array_sum(array_map(fn($i)=> ($i['entity']===$e['entity'] && $i['course']===$e['course'] && $i['status']==='paid') ? $i['amount'] : 0, $installments));
    $item['received'] = $rec;
    $item['pending']  = max(0, ($item['value'] - $rec));
    $group[$key]['items'][] = $item;
    $group[$key]['total']   += $item['value'];
    $group[$key]['received']+= $item['received'];
  }
  $entities = array_values($group);
  usort($entities, fn($a,$b)=> strcmp($a['name'],$b['name']));

  $data = [
    'created_at'   => time(),
    'totals'       => $totals,
    'upcoming'     => $upcoming,
    'entities'     => $entities,
    'installments' => $installments,
  ];
  if(!is_dir(dirname($cacheFile))) mkdir(dirname($cacheFile), 0777, true);
  file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  return $data;
}

function brl($v){ return 'R$ '.number_format((float)$v, 2, ',', '.'); }

// ====== Settings (config persistente em cache/settings.json) ======
function _settings_path(){ return __DIR__ . '/../cache/settings.json'; }

function load_settings(){
  $defaults = [
    'theme'         => 'jml',           // jml | dark | light | mono
    'accent_hue'    => 160,             // 0..360 (verde JML ~160)
    'glass'         => true,            // cards com vidro/sombra
    'density'       => 'normal',        // compact | normal | spacious
    'bg_anim_speed' => 40,              // em segundos (10..120)
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

  if (!is_dir(dirname(_settings_path()))) mkdir(dirname(_settings_path()), 0777, true);
  file_put_contents(_settings_path(), json_encode($s, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
  return $s;
}

