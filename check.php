<?php
session_start();
$cfg = require __DIR__.'/../app/config.php';
require __DIR__.'/../app/helpers.php';
if(empty($_SESSION['u'])){ header('Location: ./index.php'); exit; }

[$eng,$receb,$issues] = debug_load(); // <- usa mesmas funções de leitura (ajuste no helpers.php)

function v($n){ return 'R$ '.number_format($n,2,',','.'); }
?>
<!doctype html><meta charset="utf-8"><title>Check • <?= htmlspecialchars($cfg['APP_NAME']) ?></title>
<link rel="stylesheet" href="./assets/style.css">
<div class="container">
  <div class="header">
    <div class="brand"><div class="logo"></div><div><div class="title">Relatório de Consistência</div><div class="badge">apenas para admins</div></div></div>
    <a class="btn" href="./">Voltar</a>
  </div>

  <div class="grid">
    <div class="card" style="grid-column:span 4">
      <h4>Engajamentos lidos</h4>
      <div class="amount"><?= count($eng) ?></div>
      <div class="sub">linhas válidas do CSV principal</div>
    </div>
    <div class="card" style="grid-column:span 4">
      <h4>Recebimentos lidos</h4>
      <div class="amount"><?= count($receb) ?></div>
      <div class="sub">linhas válidas do extrato</div>
    </div>
    <div class="card" style="grid-column:span 4">
      <h4>Possíveis problemas</h4>
      <div class="amount" style="color:#fda4af"><?= count($issues) ?></div>
      <div class="sub">datas inválidas, valores estranhos, etc.</div>
    </div>
  </div>

  <div class="section-title">Anomalias</div>
  <div class="card">
    <?php if(!$issues): ?>
      <div class="alert">Sem anomalias detectadas.</div>
    <?php else: ?>
      <div class="list">
        <?php foreach($issues as $it): ?>
          <div class="item"><div class="top">
            <div><?= htmlspecialchars($it['msg']) ?></div>
            <?php if(!empty($it['valor'])): ?><span class="chip mono"><?= v($it['valor']) ?></span><?php endif; ?>
          </div></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
