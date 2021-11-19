<?php
declare(strict_types=1);
namespace ChestShop;

use pocketmine\item\ItemFactory;
use pocketmine\item\StringToItemParser;
use pocketmine\math\Vector3;

class DatabaseManager
{
	private $database;

	public function __construct(string $path)
	{
		$this->database = new \SQLite3($path);
		$this->database->exec("CREATE TABLE IF NOT EXISTS ChestShop(
					id INTEGER PRIMARY KEY AUTOINCREMENT,
					shopOwner TEXT NOT NULL,
					saleNum INTEGER NOT NULL,
					price INTEGER NOT NULL,
					productID INTEGER NOT NULL,
					productMeta INTEGER NOT NULL,
					signX INTEGER NOT NULL,
					signY INTEGER NOT NULL,
					signZ INTEGER NOT NULL,
					chestX INTEGER NOT NULL,
					chestY INTEGER NOT NULL,
					chestZ INTEGER NOT NULL
		)");
	}

	public function tryUpgradeDB() : bool
	{
		if($this->database->querySingle("SELECT count(name) FROM sqlite_Master WHERE type='table' AND name='ChestShopV2';") > 0)
			return false; // we already upgraded

		$this->database->exec("create table IF NOT EXISTS ChestShopV2(
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			shopOwner TEXT NOT NULL,
			productAmount INTEGER NOT NULL,
			price INTEGER NOT NULL,
			productID TEXT NOT NULL,
			signX INTEGER NOT NULL,
			signY INTEGER NOT NULL,
			signZ INTEGER NOT NULL,
			chestX INTEGER NOT NULL,
			chestY INTEGER NOT NULL,
			chestZ INTEGER NOT NULL
		);");
		$query = $this->database->query("SELECT * FROM ChestShop;");
		while($val = $query->fetchArray(SQLITE3_ASSOC)) {
			if(($itemName = StringToItemParser::getInstance()->parse(
				ItemFactory::getInstance()->get((int)$val['productID'], (int)$val['productMeta'])->getVanillaName()
			)?->getVanillaName()) === null) {
				continue;
			}
			$this->registerShop($val['shopOwner'], $val['saleNum'], $val['price'], $itemName, new Vector3($val['signX'], $val['signY'], $val['signZ']), new Vector3($val['chestX'], $val['chestY'], $val['chestZ']));
		}
		return false;
	}

	public function registerShop(string $shopOwner, int $productAmount, int $price, string $productID, Vector3 $sign, Vector3 $chest) : bool
	{
		return $this->database->exec("INSERT OR REPLACE INTO ChestShopV2 (id, shopOwner, productAmount, price, productID, signX, signY, signZ, chestX, chestY, chestZ) VALUES
			((SELECT id FROM ChestShop WHERE signX = $sign->x AND signY = $sign->y AND signZ = $sign->z),
			'$shopOwner', $productAmount, $price, $productID, $sign->x, $sign->y, $sign->z, $chest->x, $chest->y, $chest->z)");
	}

	public function selectByCondition(array $condition) : \SQLite3Result|bool
	{
		$where = $this->formatCondition($condition);
		return $this->database->query("SELECT * FROM ChestShopV2 WHERE $where");
	}

	public function deleteByCondition(array $condition) : bool
	{
		$where = $this->formatCondition($condition);
		return $this->database->exec("DELETE FROM ChestShopV2 WHERE $where");
	}

	private function formatCondition(array $condition) : string
	{
		$result = "";
		$first = true;
		foreach ($condition as $key => $val) {
			if ($first) $first = false;
			else $result .= "AND ";
			$result .= "$key = $val ";
		}
		return trim($result);
	}
} 