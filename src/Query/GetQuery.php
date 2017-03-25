<?php
namespace WEEEOpen\Tarallo\Query;


use WEEEOpen\Tarallo\Database;

class GetQuery extends AbstractQuery {
	const FIELD_LOCATION = 'Location';
	const FIELD_SEARCH = 'Search';
	const FIELD_SORT = 'Sort';
	const FIELD_DEPTH = 'Depth';
	const FIELD_PARENT = 'Parent';
	const FIELD_LANGUAGE = 'Language';
	const FIELD_TOKEN = 'Token';

	protected function queryFieldsFactory($query, $parameter) {
		switch($query) {
			case self::FIELD_LOCATION:
				return new QueryFieldLocation($parameter);
			case self::FIELD_SEARCH:
				return new QueryFieldSearch($parameter);
			case self::FIELD_SORT:
				return new QueryFieldSort($parameter);
			case self::FIELD_DEPTH:
				return new QueryFieldDepth($parameter);
			case self::FIELD_PARENT:
				return new QueryFieldParent($parameter);
			case self::FIELD_LANGUAGE:
				return new QueryFieldLanguage($parameter);
			case self::FIELD_TOKEN:
				return new QueryFieldToken($parameter);
			default:
				throw new \InvalidArgumentException('Unknown field ' . $query);
		}
	}

	protected function fromPieces($pieces, $requestBody) {
		$i = 0;
		$c = count($pieces);

		while($i < $c) {
			if($i + 1 < $c) {
				$previous = $this->getQueryField($pieces[ $i ]);
				if($previous === null) {
					$this->addQueryField($pieces[ $i ],
						$this->queryFieldsFactory($pieces[ $i ], $pieces[ $i + 1 ]));
				} else {
					/**
					 * @var $previous QueryField
					 */
					$previous->add($pieces[ $i + 1 ]);
				}
				$i += 2;
			} else {
				throw new \InvalidArgumentException('Missing parameter for field ' . $pieces[ $i ]);
			}
		}
	}

	public function __toString() {
		$result = '';
		$queries = $this->getAllQueryFields();
		foreach($queries as $field) {
			$result .= (string) $field;
		}

		return $result;
	}

	public function run($user, Database $db) {
		if(!$this->isBuilt()) {
			throw new \LogicException('Cannot run a query without building it first');
		}
		// TODO: Implement run() method.
	}
}