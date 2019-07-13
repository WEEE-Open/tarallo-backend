<?php


namespace WEEEOpen\Tarallo\Server;


interface ItemWithCode {
	public function getCode(): string;
	public function hasCode(): bool;
	public function compareCode(ItemWithCode $other): int;
}