<?php

namespace WEEEOpen\Tarallo\Server\Database;

use WEEEOpen\Tarallo\Server\Feature;
use WEEEOpen\Tarallo\Server\ItemIncomplete;

final class StatsDAO extends DAO {
	/**
	 * Get an AND for a WHERE clause that filters items by their location.
	 * Bind :loc to the location.
	 *
	 * @param null|ItemIncomplete $location if null returns an empty string
	 * @param string $alias Table alias, if you're doing "SELECT ItemFeatures AS alias", empty string if none
	 * @return string part of a query
	 */
	private static function filterLocation(?ItemIncomplete $location, string $alias = '') {
		if($location === null) {
			return '';
		}

		if($alias !== '') {
			$alias .= '.';
		}

		return "AND $alias`Code` IN (
SELECT Descendant
FROM Tree
WHERE Ancestor = :loc
)";
	}

	/**
	 * Get an AND for a WHERE clause that filters items by creation date (later than the specified one).
	 * Bind :timestamp to the unix timestamp.
	 *
	 * @param null|\DateTime $creation if null returns an empty string
	 * @param string $alias Table alias, if you're doing "SELECT ItemFeatures AS alias", empty string if none
	 * @return string part of a query
	 */
	private static function filterCreated(?\DateTime $creation, string $alias = '') {
		if($creation === null) {
			return '';
		}

		if($alias !== '') {
			$alias .= '.';
		}

		return "AND $alias`Code` NOT IN (
SELECT `Code`
FROM Audit
WHERE `Change` = \"C\"
AND `Time` < FROM_UNIXTIME(:timestamp)
)";
	}

	/**
	 * Get an AND for a WHERE clause that ignores deleted items.
	 *
	 * @param string $alias Table alias, if you're doing "SELECT ItemFeatures AS alias", empty string if none
	 *
	 * @return string part of a query
	 */
	private static function filterDeleted(string $alias = '') {
		if($alias !== '') {
			$alias .= '.';
		}

		return "AND $alias`Code` NOT IN (SELECT `Code` FROM `Item` WHERE DeletedAt IS NOT NULL)";
	}

	/**
	 * Get a list of all locations, ordered by number of items inside each one.
	 * Ignores deleted items as they aren't placed anywhere.
	 * No filtering by location because that doesn't make sense.
	 *
	 * @return array
	 */
	public function getLocationsByItems() {
		$array = [];

		$result = $this->getPDO()->query(/** @lang MySQL */
			<<<'EOQ'
SELECT `Code` AS Location, COUNT(*) - 1 AS Descendants
FROM ItemFeature, Tree
WHERE ItemFeature.Code = Tree.Ancestor
AND ItemFeature.Feature = 'type'
AND ItemFeature.ValueEnum = 'location'
AND `Code` NOT IN (SELECT `Code` FROM Item WHERE DeletedAt IS NOT NULL)
GROUP BY Tree.Ancestor
ORDER BY COUNT(*) DESC, Location ASC;
EOQ
, \PDO::FETCH_ASSOC);

		assert($result !== false, 'available locations');

		try {
			foreach($result as $row) {
				$array[$row['Location']] = $row['Descendants'];
			}
		} finally {
			$result->closeCursor();
		}

		return $array;
	}

	/**
	 * Count duplicate serial numbers.
	 * Considers deleted items too, because yes.
	 * No filtering by location because that doesn't make sense.
	 *
	 * @return array
	 */
	public function getDuplicateSerialsCount() {
		$array = [];

		$result = $this->getPDO()->query('SELECT ValueText AS SN, COUNT(*) AS Count
FROM ItemFeature
WHERE Feature = \'sn\'
GROUP BY ValueText
HAVING Count > 1
ORDER BY Count DESC, SN ASC', \PDO::FETCH_ASSOC);

		assert($result !== false, 'duplicate serial numbers');

		try {
			foreach($result as $row) {
				$array[$row['SN']] = $row['Count'];
			}
		} finally {
			$result->closeCursor();
		}

		return $array;
	}

	/**
	 * Get most/least recently changed cases in a particular location, excluding in-use ones. This takes into account
	 * all audit entries for all contained items.
	 * Deleted items are ignored since they aren't in any location.
	 *
	 * Any attempt to make the function more generic failed miserably or was escessively complex, but consider
	 * that this is a very specific kind of stat to begin with...
	 * @todo parametrize the "in-use" exclusion, maybe? So the "most recently modified" makes more sense
	 * @todo try to parametrize the "type=case" filter
	 *
	 * @param ItemIncomplete $location Where to look, null to search everywhere
	 * @param bool $recent True for more recently modified items first, false for least recently modified
	 * @param int $limit rows to return
	 *
	 * @return int[] code => timestamp
	 */
	public function getModifiedItems(?ItemIncomplete $location, bool $recent = true, int $limit = 100): array {
		$array = [];

		if($location !== null) {
			$locationPart = 'AND `Ancestor` IN (
	SELECT Descendant
	FROM Tree
	WHERE Ancestor = :loc
)';
		} else {
			$locationPart = '';
		}

		$query = "SELECT `Ancestor` AS `Item`, `Time`, UNIX_TIMESTAMP(MAX(`Time`)) AS `Last`
FROM Audit
JOIN Tree ON Tree.Descendant=Audit.Code
WHERE `Ancestor` IN (
	SELECT `Code`
	FROM ItemFeature
	WHERE Feature = 'type' AND `ValueEnum` = 'case'
)
$locationPart
AND `Ancestor` NOT IN (
	SELECT `Code`
	FROM ItemFeature
	WHERE Feature = 'restrictions' AND `ValueEnum` = 'in-use'
)
GROUP BY `Ancestor`
ORDER BY `Last` " . ($recent ? 'DESC' : 'ASC') . '
LIMIT :lim';
		$statement = $this->getPDO()->prepare($query);

		if($location !== null) {
			$statement->bindValue(':loc', $location->getCode(), \PDO::PARAM_STR);
		}
		$statement->bindValue(':lim', $limit, \PDO::PARAM_INT);

		try {
			$success = $statement->execute();
			assert($success);

			while($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
				$array[$row['Item']] = $row['Last'];
			}
		} finally {
			$statement->closeCursor();
		}

		return $array;
	}

	/**
	 * Count how many items have each possible value for a feature
	 *
	 * e.g. with feature name = "color":
	 * - red: 10
	 * - yellow: 6
	 * - grey: 4
	 * and so on.
	 *
	 * If some (enum) values aren't assigned to an item they're not reported, actually,
	 * so it's not really every possible value.
	 *
	 * @param string $feature Feature name
	 * @param Feature $filter
	 * @param ItemIncomplete $location
	 * @param null|\DateTime $creation creation date (starts from here)
	 * @param bool $deleted Also count deleted items, defaults to false (don't count them)
	 * @return int[] value => count, sorted by count descending
	 */
	public function getCountByFeature(string $feature, Feature $filter, ?ItemIncomplete $location = null, ?\DateTime $creation = null, bool $deleted = false) {
		Feature::validateFeatureName($feature);

		$array = [];

		$locationFilter = self::filterLocation($location);
		$deletedFilter = $deleted ? '' : self::filterDeleted();
		$createdFilter = self::filterCreated($creation);

		$query = "SELECT COALESCE(`Value`, ValueText, ValueEnum, ValueDouble) as Val, COUNT(*) AS Quantity
FROM ItemFeature
WHERE Feature = :feat
AND `Code` IN (
  SELECT `Code`
  FROM ItemFeature
  WHERE Feature = :nam AND COALESCE(`Value`, ValueText, ValueEnum, ValueDouble) = :val
)
$locationFilter
$deletedFilter
$createdFilter
GROUP BY Val
ORDER BY Quantity DESC";

		$statement = $this->getPDO()->prepare($query);

		$statement->bindValue(':feat', $feature, \PDO::PARAM_STR);
		$statement->bindValue(':val', $filter->value);
		$statement->bindValue(':nam', $filter->name, \PDO::PARAM_STR);
		if($location !== null) {
			$statement->bindValue(':loc', $location->getCode(), \PDO::PARAM_STR);
		}
		if($creation !== null) {
			$statement->bindValue(':timestamp', $creation->getTimestamp(), \PDO::PARAM_INT);
		}

		try {
			$success = $statement->execute();
			assert($success, 'count by feature');
			while($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
				$array[$row['Val']] = $row['Quantity'];
			}
		} finally {
			$statement->closeCursor();
		}

		return $array;
	}

	/**
	 * Get all items that have a certain value (exact match) for a feature.
	 * For anything more complicated use SearchDAO facilities.
	 *
	 * @param Feature $feature Feature and value to search
	 * @param int $limit Maximum number of results
	 * @param null|ItemIncomplete $location
	 * @param null|\DateTime $creation creation date (starts from here)
	 * @param bool $deleted Also count deleted items, defaults to false (don't count them)
	 *
	 * @return ItemIncomplete[] Items that have that feature (or empty array if none)
	 */
	public function getItemsByFeatures(Feature $feature, ?ItemIncomplete $location = null, int $limit = 100, ?\DateTime $creation = null, bool $deleted = false): array {
		$pdo = $this->getPDO();
		$locationFilter = self::filterLocation($location);
		$deletedFilter = $deleted ? '' : self::filterDeleted();
		$createdFilter = self::filterCreated($creation);

		/** @noinspection SqlResolve */
		$query = "SELECT `Code`
FROM ItemFeature
WHERE Feature = :feat
AND COALESCE(`Value`, ValueText, ValueEnum, ValueDouble) = :val
$locationFilter
$deletedFilter
$createdFilter
LIMIT :lim";
		$statement = $pdo->prepare($query);

		$statement->bindValue(':feat', $feature->name, \PDO::PARAM_STR);
		$statement->bindValue(':val', $feature->value);
		$statement->bindValue(':lim', $limit, \PDO::PARAM_INT);
		if($location !== null) {
			$statement->bindValue(':loc', $location->getCode(), \PDO::PARAM_STR);
		}
		if($creation !== null) {
			$statement->bindValue(':timestamp', $creation->getTimestamp(), \PDO::PARAM_INT);
		}

		$result = [];

		try {
			$success = $statement->execute();
			assert($success, 'get items by features');
			while($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
				$result[] = new ItemIncomplete($row['Code']);
			}
		} finally {
			$statement->closeCursor();
		}

		return $result;
	}

	/**
	 * Get all items that don't have a feature at all.
	 *
	 * @param Feature $filter feature that should be there
	 * (to at least set item type, you'll need it unless you want to receive the entire database, basically...)
	 * @param string $notFeature Feature that should not be present at all
	 * @param null|ItemIncomplete $location
	 * @param int $limit Maximum number of results
	 * @param null|\DateTime $creation creation date (starts from here)
	 * @param bool $deleted Also count deleted items, defaults to false (don't count them)
	 *
	 * @return ItemIncomplete[] Items that have that feature (or empty array if none)
	 */
	public function getItemByNotFeature(Feature $filter, string $notFeature, ?ItemIncomplete $location = null, int $limit = 100, ?\DateTime $creation = null, bool $deleted = false): array {

		$locationFilter = self::filterLocation($location);
		$deletedFilter = $deleted ? '' : self::filterDeleted();
		$createdFilter = self::filterCreated($creation);

		$query = "SELECT Code 
FROM ItemFeature 
WHERE Feature = :type 
AND COALESCE(`Value`, ValueText, ValueEnum, ValueDouble) = :val
$locationFilter
$deletedFilter
$createdFilter
AND Code NOT IN ( 
SELECT `Code` 
FROM ItemFeature 
WHERE Feature = :notF
)
LIMIT :lim";
		$statement = $this->getPDO()->prepare($query);

		$statement->bindValue(':type', $filter->name, \PDO::PARAM_STR);
		$statement->bindValue(':val', $filter->value);
		$statement->bindValue(':notF', $notFeature, \PDO::PARAM_STR);
		$statement->bindValue(':lim', $limit, \PDO::PARAM_INT);
		if($location !== null) {
			$statement->bindValue(':loc', $location->getCode(), \PDO::PARAM_STR);
		}
		if($creation !== null) {
			$statement->bindValue(':timestamp', $creation->getTimestamp(), \PDO::PARAM_INT);
		}

		$result = [];

		try {
			$success = $statement->execute();
			assert($success, 'get items by NOT features');
			while($row = $statement->fetch(\PDO::FETCH_ASSOC)) {
				$result[] = new ItemIncomplete($row['Code']);
			}
		} finally {
			$statement->closeCursor();
		}

		return $result;
	}

	/**
	 * Get all items that have a certain value (exact match) for a feature.
	 * For anything more complicated use SearchDAO facilities.
	 *
	 * @param Feature $filter feature that should be there (use to select item type, maybe?)
	 * @param string[] $features
	 * @param null|ItemIncomplete $location
	 * @param null|\DateTime $creation creation date (starts from here)
	 * @param bool $deleted Also count deleted items, defaults to false (don't count them)
	 *
	 * @return ItemIncomplete[] Items that have that feature (or empty array if none)
	 */
	public function getRollupCountByFeature(Feature $filter, array $features, ?ItemIncomplete $location = null, ?\DateTime $creation = null, bool $deleted = false): array {
		/*
		 * This was a nice and readable query that rolls up (with a series of join(t)s, manco a farlo apposta...) the
		 * RAMs. To make it generic it became almost unreadable, but the final result should be somewhat like this...
		 *
		 * SELECT a.ValueEnum AS Type,
		 * b.ValueEnum AS FormFactor,
		 * c.Value AS Frequency,
		 * COUNT(*) AS Quantity
		 * FROM ItemFeature AS a
		 * JOIN ItemFeature AS b ON a.Code=b.Code
		 * JOIN ItemFeature AS c ON b.Code=c.Code
		 * WHERE a.Feature = 'ram-type'
		 *   AND b.feature = 'ram-form-factor'
		 *   AND c.Feature = 'frequency-hertz'
		 *   AND a.Code IN (
		 *     SELECT Code
		 *     FROM ItemFeature
		 *     WHERE Feature = 'type'
		 *     AND ValueEnum = 'ram'
		 * )
		 * GROUP BY Type, FormFactor, Frequency WITH ROLLUP;
		 */
		if(empty($features)) {
			throw new \LogicException('Nothing roll up in');
		}
		// Remove any manually set array keys, since these will go into te query without any sanitizations.
		// This guarantees there are only numbers.
		$features = array_values($features);

		$locationFilter = self::filterLocation($location, 'f0');
		$deletedFilter = $deleted ? '' : self::filterDeleted('f0');
		$createdFilter = self::filterCreated($creation, 'f0');

		$select = 'SELECT ';
		$from = 'FROM ItemFeature AS f0 '; // $f0 is guaranteed to exist, since the array is not empty
		$where = 'WHERE f0.`Code` IN (
  SELECT `Code`
  FROM ItemFeature
  WHERE Feature = :nam AND COALESCE(ValueEnum, `Value`, ValueText, ValueDouble) = :val
) ';
		// Will produce e.g. `ram-type`,`ram-form-factor`,`frequency-hertz`
		$group = implode("`,`", $features);
		$group = "`$group`";

		foreach($features as $i => $feature) {
			$select .= "COALESCE(f$i.ValueEnum, f$i.`Value`, f$i.ValueText, f$i.ValueDouble) AS `$feature`, ";
			if($i > 0) {
				$from .= " JOIN ItemFeature AS f$i ON f0.Code=f$i.Code";
			}
			$where .= " AND f$i.`Feature` = :fname$i";
		}
		$select .= 'COUNT(*) AS Quantity';

		$query = "$select
$from
$where
$locationFilter
$deletedFilter
$createdFilter
GROUP BY $group WITH ROLLUP";
		$statement = $this->getPDO()->prepare($query);

		foreach($features as $i => $feature) {
			$statement->bindValue(":fname$i", $feature);
		}
		$statement->bindValue(':nam', $filter->name, \PDO::PARAM_STR);
		$statement->bindValue(':val', $filter->value);
		if($location !== null) {
			$statement->bindValue(':loc', $location->getCode(), \PDO::PARAM_STR);
		}
		if($creation !== null) {
			$statement->bindValue(':timestamp', $creation->getTimestamp(), \PDO::PARAM_INT);
		}

		try {
			$success = $statement->execute();
			assert($success, 'get rollup count');
			$result = $statement->fetchAll(\PDO::FETCH_ASSOC);
			// Cast integers to integers, doubles to doubles... basically ignore this part and imagine that MySQL
			// returns the correct type even with COALESCE
			$cast = [];
			foreach($features as $feature) {
				if(Feature::getType($feature) === Feature::INTEGER || Feature::getType($feature) === Feature::DOUBLE) {
					$cast[] = $feature;
				}
			}
			if(!empty($cast)) {
				foreach($result as &$row) {
					foreach($cast as $feature) {
						if($row[$feature] !== null) {
							if(Feature::getType($feature) === Feature::INTEGER) {
								$row[$feature] = (int) $row[$feature];
							} else if(Feature::getType($feature) === Feature::DOUBLE) {
								$row[$feature] = (double) $row[$feature];
							}
						}
					}
				}
			}
			return $result;
		} finally {
			$statement->closeCursor();
		}
	}
}
