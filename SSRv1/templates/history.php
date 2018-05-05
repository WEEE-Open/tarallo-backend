<?php
/** @var \WEEEOpen\Tarallo\Server\User $user */
/** @var \WEEEOpen\Tarallo\Server\Item $item */
/** @var bool $deleted */
/** @var string[][] $history */
$this->layout('main', ['title' => $item->getCode() . ' history', 'user' => $user, 'itembuttons' => true]);
?>

<?php if(!$recursion): $this->insert('breadcrumbs', ['item' => $item]); endif; ?>
<article class="item root<?= $deleted ? ' deleted' : '' ?>"
		data-code="<?=$this->e($item->getCode())?>">
	<header>
		<h2 id="code-<?=$this->e($item->getCode())?>"><?=$this->e($item->getCode())?></h2>
		<?php if($deleted): ?>
		<div class="error message">❌️️&nbsp;This item has been deleted</div>
		<?php endif; ?>
	</header>

	<nav class="itembuttons" data-for-item="<?=$this->e($item->getCode())?>">
		<button class="view">🔍&nbsp;View</button>
	</nav>

	<section class="history">
		<?php if(empty($history)): ?>
			<p>Nothing to show</p>
		<?php else: ?>
			<p>This is still in beta. Actions in reverse order (most recent first), M means Move (to the "Other" column), C create, U update.</p>
			<table>
				<thead>
				<tr>
					<td>Change</td>
					<td>Other</td>
					<td>Time</td>
					<td>Operator</td>
				</tr>
				</thead>
				<tbody>
				<?php date_default_timezone_set('Europe/Rome'); foreach($history as $row): ?>
					<tr>
						<td><?= $row['change'] ?></td>
						<td><?= $row['other'] ?? '' ?></td>
						<td><?=date('Y-m-d, H:i', $row['time'])?></td>
						<td><?= $row['user'] ?></td>
					</tr>
				<?php endforeach ?>
				</tbody>
			</table>
		<?php endif ?>
	</section>
</article>
