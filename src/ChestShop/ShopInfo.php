<?php

namespace ChestShop;

class ShopInfo {
	public string $shopOwner;
	public int $productAmount;
	public float $price;
	public string $productName;
	public int $signX;
	public int $signY;
	public int $signZ;
	public int $chestX;
	public int $chestY;
	public int $chestZ;

	public function __construct(string $shopOwner, int $productAmount, float $price, string $productName, int $signX, int $signY, int $signZ, int $chestX, int $chestY, int $chestZ) {
		$this->shopOwner = $shopOwner;
		$this->productAmount = $productAmount;
		$this->price = $price;
		$this->productName = $productName;
		$this->signX = $signX;
		$this->signY = $signY;
		$this->signZ = $signZ;
		$this->chestX = $chestX;
		$this->chestY = $chestY;
		$this->chestZ = $chestZ;
	}
}