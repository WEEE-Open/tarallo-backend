<?php

namespace WEEEOpen\Tarallo\Server\Database;

use WEEEOpen\Tarallo\Server\Feature;
use WEEEOpen\Tarallo\Server\Item;
use WEEEOpen\Tarallo\Server\ItemIncomplete;
use WEEEOpen\Tarallo\Server\Search;
use WEEEOpen\Tarallo\Server\SearchTriplet;
use WEEEOpen\Tarallo\Server\User;

final class SearchDAO extends DAO {
	private static function getCompare(SearchTriplet $triplet, $id) {
		$feature = $triplet->getAsFeature();
		$operator = $triplet->getCompare();
		switch($feature->type) {
			case Feature::STRING:
				switch($operator) {
					case '=':
					case '<>':
						return "ValueText $operator :param$id";
					case '~':
						return "ValueText LIKE :param$id";
					case '!~':
						return "ValueText NOT LIKE :param$id";
				}
				break;
			case Feature::INTEGER:
			case Feature::DOUBLE:
				$column = $feature->type === Feature::INTEGER ? 'Value' : 'ValueDouble';
				switch($operator) {
					case '>':
					case '<':
					case '>=':
					case '<=':
					case '<>':
					case '=':
						return "$column $operator :param$id";
				}
				break;
			case Feature::ENUM:
				switch($operator) {
					case '=':
					case '<>':
						return "ValueEnum $operator :param$id";
				}
				break;
		}
		throw new \InvalidArgumentException("Cannot apply filter $triplet");
	}

	/**
	 * Begin searching. By obtaining an ID for this search, setting its expiration date, and the like.
	 *
	 * @param User $user
	 *
	 * @return int
	 */
	private function newSearch(User $user) {
		$s = $this->getPDO()->prepare('INSERT INTO Search(`Owner`) VALUES (?)');
		$result = $s->execute([$user->getUsername()]);

		if($result) {
			return (int) $this->getPDO()->lastInsertId();
		} else {
			throw new DatabaseException('Cannot start search for unfathomable reasons');
		}
	}

	/**
	 * @param Search $search Filters to be applied
	 * @param User $user Search owner (current user)
	 * @param int|null $previousSearchId If supplied, previous results are filtered again
	 *
	 * @return int Search ID, previous or new
	 */
	public function search(Search $search, User $user, $previousSearchId = null) {
		$i = 0;
		$subqueries = [];

		if($previousSearchId === null) {
			$id = self::newSearch($user);
		} else {
			$id = $previousSearchId;
		}

		if($search->searchFeatures !== null) {
			foreach($search->searchFeatures as $triplet) {
				/** @var $triplet SearchTriplet */
				$compare = self::getCompare($triplet, $i);

				$subqueries[] = /** @lang MySQL */
					<<<EOQ
				SELECT `Code`
				FROM ItemFeature -- , ProductFeature
				WHERE Feature = :fn$i
				AND $compare
				-- AND Item.Brand=ProductFeature.Brand
				-- AND Item.Model=ProductFeature.Model
				-- AND Item.Variant=ProductFeature.Variant
EOQ;
				$i++;
			}
		}

		if($search->searchLocations !== null) {
			foreach($search->searchLocations as $location) {
				$subqueries[] = /** @lang MySQL */
					<<<EOQ
			SELECT `Descendant`
			FROM Tree
			WHERE Ancestor = :param$i
EOQ;
				$i++;
			}
		}

		if($search->searchAncestors !== null) {
			foreach($search->searchAncestors as $ancestorTriplet) {
				/** @var $ancestorTriplet SearchTriplet */
				$compare = self::getCompare($ancestorTriplet, $i);

				$subqueries[] = /** @lang MySQL */
					<<<EOQ
			SELECT `Descendant`
			FROM ItemFeature, Tree
			WHERE ItemFeature.Code=Tree.Ancestor
			AND Feature = :fn$i
			AND $compare
EOQ;
				$i++;
			}
		}

		if($search->searchCode === null) {
			$codeSubquery = '';
		} else {
			$codeSubquery = '`Code` LIKE :cs';
		}

		$everything = '';
		foreach($subqueries as $subquery) {
			$everything .= "AND Item.`Code` IN (\n";
			$everything .= $subquery;
			$everything .= "\n)";
		}

		if($codeSubquery !== '') {
			$everything .= "AND $codeSubquery";
		}

		// Replace first AND with WHERE
		$everything = 'WHERE' . substr($everything, 3);

		$megaquery = /** @lang MySQL */
			<<<EOQ
INSERT INTO SearchResult(Search, Item)
SELECT DISTINCT :searchId, Item.`Code`
FROM Item, ItemFeature
$everything
ORDER BY Item.`Code`;
EOQ;

		$statement = $this->getPDO()->prepare($megaquery);
		$statement->bindValue(":searchId", $id, \PDO::PARAM_INT);

		$i = 0;
		if($search->searchFeatures !== null) {
			foreach($search->searchFeatures as $triplet) {
				$pdoType = $triplet->getAsFeature()->value === Feature::INTEGER ? \PDO::PARAM_INT : \PDO::PARAM_STR;
				$statement->bindValue(":fn$i", $triplet->getKey(), \PDO::PARAM_STR);
				$statement->bindValue(":param$i", $triplet->getValue(), $pdoType);
				$i++;
			}
		}
		if($search->searchLocations !== null) {
			foreach($search->searchLocations as $location) {
				$statement->bindValue(":param$i", $location, \PDO::PARAM_STR);
				$i++;
			}
		}
		if($search->searchAncestors !== null) {
			foreach($search->searchAncestors as $triplet) {
				$pdoType = $triplet->getAsFeature()->value === Feature::INTEGER ? \PDO::PARAM_INT : \PDO::PARAM_STR;
				$statement->bindValue(":fn$i", $triplet->getKey(), \PDO::PARAM_STR);
				$statement->bindValue(":param$i", $triplet->getValue(), $pdoType);
				$i++;
			}
		}
		if($search->searchCode !== null) {
			$statement->bindValue(":cs", $search->searchCode);
		}
		if(!$statement->execute()) {
			throw new DatabaseException('Cannot execute search for unknown reasons');
		}

		$this->sort($search, $id);

		return $id;
	}

	public function sort(Search $search, $searchId) {
		if($search->sort === null) {
			return;
		}

		if(!is_array($search->sort)) {
			throw new \InvalidArgumentException('"Sorts" must be an array');
		}

		if(empty($search->sort)) {
			return;
		}

		if(!is_int($searchId)) {
			throw new \InvalidArgumentException('Search ID must be an integer');
		}

		reset($search->sort);
		$featureName = key($search->sort);

		// TODO: see if this thing works
		return;

		$miniquery = /** @lang MySQL */ 'CALL rankResults(?, ?, ?);';

		$statement = $this->getPDO()->prepare($miniquery);
		try {
			if(!$statement->execute([$searchId, $featureName, $search->sort[$featureName]])) {
				throw new DatabaseException('Sorting failed for no apparent reason');
			}
		} finally {
			$statement->closeCursor();
		}
	}

	private $getResultsStatement;

	/**
	 * Get results from a previous search, already sorted.
	 *
	 * @param int $id Search ID
	 * @param int $page Current page, starting from 1
	 * @param int $perPage Items per page
	 * @param int|null $depth Depth of each Item tree
	 *
	 * @return Item[]
	 */
	public function getResults($id, $page, $perPage, $depth = null) {
		if(!is_int($id)) {
			throw new \InvalidArgumentException('Search ID must be an integer');
		}

		if(!is_int($page)) {
			throw new \InvalidArgumentException('Page number must be an integer');
		}

		if(!is_int($perPage)) {
			throw new \InvalidArgumentException('"Items per page" must be an integer');
		}

		if($this->getResultsStatement === null) {
			$this->getResultsStatement = /** @lang MySQL */
				'SELECT `Item` FROM SearchResult WHERE Search = :id ORDER BY `Order` ASC LIMIT :offs, :cnt';
		}

		$statement = $this->getPDO()->prepare($this->getResultsStatement);
		$items = [];
		$itemDAO = $this->database->itemDAO();

		try {
			$statement->bindValue(':id', $id, \PDO::PARAM_INT);
			$statement->bindValue(':offs', ($page - 1) * $perPage, \PDO::PARAM_INT);
			$statement->bindValue(':cnt', $perPage, \PDO::PARAM_INT);
			$statement->execute();
			foreach($statement as $result) {
				$items[] = $itemDAO->getItem(new ItemIncomplete($result['Item']), $depth);
			}
		} finally {
			$statement->closeCursor();
		}

		return $items;
	}
}