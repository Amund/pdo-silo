<?php

// 2016 - Silo 1.0

// MIT License
// Copyright (c) 2016 Dimitri Avenel

// Permission is hereby granted, free of charge, to any person obtaining
// a copy of this software and associated documentation files (the
// "Software"), to deal in the Software without restriction, including
// without limitation the rights to use, copy, modify, merge, publish,
// distribute, sublicense, and/or sell copies of the Software, and to
// permit persons to whom the Software is furnished to do so, subject to
// the following conditions:

// The above copyright notice and this permission notice shall be
// included in all copies or substantial portions of the Software.

// THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
// EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
// MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
// NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE
// LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION
// OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION
// WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

class SiloException extends Exception {}

class Silo {

	public $cache = TRUE;
	public $cacheType;
	public $cachePath;

	private $pdo;
	private $prefix;
	private $stmt = array();
	private $_cache = TRUE;

	private static $sql = [
		'select-meta'      => 'SELECT * FROM PREFIX_meta WHERE id=?',
		'insert-meta'      => 'INSERT INTO PREFIX_meta ( class ) VALUES ( ? )',
		'update-meta'      => 'UPDATE PREFIX_meta SET class=? WHERE id=?',
		'delete-meta'      => 'DELETE FROM PREFIX_meta WHERE id=?',

		'select-attr'      => 'SELECT value FROM PREFIX_attribute WHERE id=? AND attribute =? LIMIT 1',
		'select-all-attr'  => 'SELECT attribute, value FROM PREFIX_attribute WHERE id=?',
		'replace-attr'     => 'REPLACE INTO PREFIX_attribute ( id, attribute, value ) VALUES ( ?, ?, ? )',
		'delete-attr'      => 'DELETE FROM PREFIX_attribute WHERE id=? AND attribute=?',
		'delete-all-attr'  => 'DELETE FROM PREFIX_attribute WHERE id=?',

		'replace-link'     => 'REPLACE INTO PREFIX_link ( id_parent, id_child, attribute ) VALUES ( ?, ?, ? )',
		'delete-link'      => 'DELETE FROM PREFIX_link WHERE id_parent=? AND id_child=?',
		'delete-link-from' => 'DELETE FROM PREFIX_link WHERE id_parent=?',
		'delete-link-to'   => 'DELETE FROM PREFIX_link WHERE id_child=?',

		'select-cache'     => 'SELECT resource FROM PREFIX_cache WHERE id=?',
		'replace-cache'    => 'REPLACE INTO PREFIX_cache ( id, resource ) VALUES ( ?, ? )',
		'delete-cache'     => 'DELETE FROM PREFIX_cache WHERE id=?',
		'delete-all-cache' => 'DELETE FROM PREFIX_cache',

		'select-children'  => 'SELECT attribute, id_child FROM PREFIX_link WHERE id_parent=?',
		'select-parents'   => 'SELECT attribute, id_parent FROM PREFIX_link WHERE id_child=?',
	];

	private static $schema = [
		'mysql' => [
			'CREATE TABLE IF NOT EXISTS `PREFIX_meta` ( `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT, `class` VARCHAR(255) NOT NULL, PRIMARY KEY (`id`), INDEX `class` (`class`) ) ENGINE=MyISAM',
			'CREATE TABLE IF NOT EXISTS `PREFIX_attribute` ( `id` INT(10) UNSIGNED NOT NULL, `attribute` VARCHAR(255) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`id`, `attribute`), INDEX `attribute` (`attribute`), INDEX `id` (`id`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
			'CREATE TABLE IF NOT EXISTS `PREFIX_link` ( `id_parent` INT(10) UNSIGNED NOT NULL, `id_child` INT(10) UNSIGNED NOT NULL, `attribute` VARCHAR(255) NOT NULL, PRIMARY KEY (`id_parent`, `id_child`, `attribute`), INDEX `id_parent` (`id_parent`), INDEX `id_child` (`id_child`), INDEX `attribute` (`attribute`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
			'CREATE TABLE IF NOT EXISTS `PREFIX_cache` ( `id` INT(10) UNSIGNED NOT NULL, `resource` LONGTEXT NOT NULL, PRIMARY KEY (`id`) ) COLLATE="utf8_unicode_ci" ENGINE=MyISAM',
		],
		'sqlite' => [
			'CREATE TABLE IF NOT EXISTS `PREFIX_meta` ( `id` INTEGER PRIMARY KEY, `class` VARCHAR(255) NOT NULL )',
			'CREATE TABLE IF NOT EXISTS `PREFIX_attribute` ( `id` INT(10) NOT NULL, `attribute` VARCHAR(255) NOT NULL, `value` LONGTEXT NOT NULL, PRIMARY KEY (`id`, `attribute`) )',
			'CREATE TABLE IF NOT EXISTS `PREFIX_link` ( `id_parent` INT(10) NOT NULL, `id_child` INT(10) NOT NULL, `attribute` VARCHAR(255) NOT NULL, PRIMARY KEY (`id_parent`, `id_child`, `attribute`) )',
			'CREATE TABLE IF NOT EXISTS `PREFIX_cache` ( `id` INT(10) NOT NULL, `resource` LONGTEXT NOT NULL, PRIMARY KEY (`id`) )',
			'CREATE INDEX `class` ON `PREFIX_meta` (`class` ASC)',
			'CREATE INDEX `attribute` ON `PREFIX_attribute` (`attribute` ASC)',
			'CREATE INDEX `id` ON `PREFIX_attribute` (`id` ASC)',
			'CREATE INDEX `id_parent` ON `PREFIX_link` (`id_parent` ASC)',
			'CREATE INDEX `id_child` ON `PREFIX_link` (`id_child` ASC)',
			'CREATE INDEX `linkattribute` ON `PREFIX_link` (`attribute` ASC)',
		]
	];

	public function __construct( $pdo, $prefix='resource', $cache=NULL ) {
		$this->pdo = $pdo;
		if( !empty( $prefix ) )
			$this->prefix = strtolower( $prefix );
		if( empty( $cache ) ) {
			$this->cacheType = 'pdo';
		} else {
			$this->cacheType = 'disk';
			$this->cachePath = (string) $cache;
		}
	}

	/* Create silo tables with current prefix
	$silo->create()
		return nothing
	*/
	public function create() {
		$driver = $this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
		if( !in_array( $driver, array_keys( self::$schema ) ) )
			throw new SiloException( 'Unsupported PDO driver' );
		foreach( self::$schema[$driver] as $sql ) {
			$sql = str_replace( 'PREFIX', $this->prefix, $sql );
			$this->pdo->exec( $sql );
		}
	}

	/* Destroy silo tables with current prefix (warning: with data!)
	*/
	public function destroy() {
		$this->pdo->exec( 'DROP TABLE IF EXISTS '.$this->prefix.'_meta' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS '.$this->prefix.'_attribute' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS '.$this->prefix.'_link' );
		$this->pdo->exec( 'DROP TABLE IF EXISTS '.$this->prefix.'_cache' );
	}


	/* Store a new resource of class $class, with attributes $attributes
	$silo->set( $class, $attributes )
		return new resource $id
	*/
	public function set( $class, $attributes=array(), $get=FALSE ) {
		$this->_cache = FALSE;
		$id = $this->meta( NULL, $class );
		if( !empty( $attributes ) )
			$this->attributes( $id, $attributes );
		$this->_cache = TRUE;
		if( $this->cache && $this->_cache ) {
			$this->_cache = FALSE;
			$this->setCache( $id, $this->get( $id ) );
			$this->_cache = TRUE;
		}
		return ( $get === TRUE ? $this->get( $id ) : $id );
	}

	/* Retrieve a resource from its $id, with or without its linked resources
	$silo->get( $id )
		return resource n° $id, or NULL
	$silo->get( $id, TRUE )
		return resource n° $id and its links id, or NULL
	$silo->get( $id, TRUE, TRUE )
		return resource n° $id and its linked resources, or NULL
	*/
	public function get( $id, $links=FALSE, $getLinks=FALSE ) {
		if( $this->cache && $this->_cache ) {
			$resource = $this->getCache( $id );
			if( $resource !== FALSE ) {
				if( $links === TRUE ) {
					$resource['links']['from'] = $this->to( $id, $getLinks );
					$resource['links']['to'] = $this->from( $id, $getLinks );
				}
				return $resource;
			}
		}
		$meta = $this->meta( $id );
		if( !$meta )
			return NULL;
		$attributes = $this->attributes( $id );
		$resource = array_merge( $meta, $attributes );
		if( $this->cache && $this->_cache )
			$this->setCache( $id, $resource );
		if( $links === TRUE ) {
			$resource['links']['from'] = $this->to( $id, $getLinks );
			$resource['links']['to'] = $this->from( $id, $getLinks );
		}
		return $resource;
	}

	/* Get or set a resource meta
	$silo->meta( $id )
		return meta n° $id, or FALSE
	$silo->meta( $id, $class )
		add or modify the class of meta $id to $class, and return $id
		if $id is empty ('', 0, FALSE or NULL), this meta is added
		if $id is an integer, and the corresponding meta exists, its class is updated
	*/
	public function meta() {
		$nbArgs = func_num_args();
		switch( $nbArgs ) {
			case 1:
				// GET
				list( $id ) = func_get_args();
				$stmt = $this->prepare( 'select-meta' );
				$stmt->execute( array( $id ) );
				$meta = $stmt->fetch( PDO::FETCH_ASSOC );
				if( !is_array( $meta ) )
					return FALSE;
				return $meta;
			case 2:
				// SET
				list( $id, $class ) = func_get_args();
				if( !empty( $id ) && !is_int( $id ) )
					trigger_error( 'Bad id', E_USER_ERROR );
				if( empty( $id ) )
					$this->prepare( 'insert-meta' )->execute( array( $class ) );
				else
					$this->prepare( 'update-meta' )->execute( array( $class, $id ) );
				if( empty( $id ) )
					$id = $this->pdo->lastInsertId();
				if( $this->cache && $this->_cache ) {
					$this->_cache = FALSE;
					$this->setCache( $id, $this->get( $id ) );
					$this->_cache = TRUE;
				}
				return (int) $id;
			default: trigger_error( 'Bad arguments count', E_USER_ERROR );
		}
	}

	/* Get or set a resource attribute
	$silo->attr( $id, $attr )
		return attribute $attr of resource n° $id, or NULL
	$silo->attr( $id, $attr, $value )
		set attribute $attr of resource n° $id to $value, and return $value
		if $value is empty ('', 0, FALSE or NULL), remove this attribute from database, and return NULL
	*/
	public function attr() {
		$nbArgs = func_num_args();
		switch( $nbArgs ) {
			case 2:
				// GET
				list( $id, $attr ) = func_get_args();
				$stmt = $this->prepare( 'select-attr' );
				$stmt->execute( array( $id, $attr ) );
				$value = $stmt->fetch( PDO::FETCH_COLUMN );
				if( !$value )
					return NULL;
				return $value;
			case 3:
				// SET
				list( $id, $attr, $value ) = func_get_args();
				$lowerAttr = strtolower( $attr );
				if( $lowerAttr === 'id' || $lowerAttr === 'class' || $lowerAttr === 'links' )
					return NULL;
				if( is_scalar( $value ) || is_null( $value ) ) {
					if( empty( $value ) ) {
						// delete attribute
						$stmt = $this->prepare( 'delete-attr' );
						$stmt->execute( array( $id, $attr ) );
						$value = NULL;
					} else {
						// insert or replace attribute
						$stmt = $this->prepare( 'replace-attr' );
						$stmt->execute( array( $id, $attr, $value ) );
					}
				} else {
					trigger_error( 'Attribute value is not scalar', E_USER_ERROR );
				}
				if( $this->cache && $this->_cache ) {
					$this->_cache = FALSE;
					$this->setCache( $id, $this->get( $id ) );
					$this->_cache = TRUE;
				}
				return $value;
			default: trigger_error( 'Bad arguments count', E_USER_ERROR );
		}
	}

	/* Get or set resource attributes
	$silo->attributes( $id )
		return array of attributes $attributes from resource n° $id, or NULL
	$silo->attributes( $id, $attributes )
		set array of attributes $attributes for resource n° $id, and return $attributes
	*/
	public function attributes() {
		$nbArgs = func_num_args();
		switch( $nbArgs ) {
			case 1:
				// GET
				list( $id ) = func_get_args();
				$stmt = $this->prepare( 'select-all-attr' );
				$stmt->execute( array( $id ) );
				$attributes = array();
				while( $row = $stmt->fetch( PDO::FETCH_ASSOC ) )
					$attributes[$row['attribute']] = $row['value'];
				return $attributes;
			case 2:
				// SET
				list( $id, $attributes ) = func_get_args();
				if( is_array( $attributes ) || empty( $attribute ) ) {
					if( is_array( $attributes ) ) {
						// insert or replace all attributes
						$this->_cache = FALSE;
						$this->attributes( $id, NULL );
						foreach( $attributes as $k=>$v )
							$this->attr( $id, $k, $v );
						$this->_cache = TRUE;
					} else {
						// delete all attributes
						$stmt = $this->prepare( 'delete-all-attr' );
						$stmt->execute( array( $id ) );
						$attributes = NULL;
					}
				} else {
					trigger_error( 'Attributes must be an array or empty', E_USER_ERROR );
				}
				if( $this->cache && $this->_cache ) {
					$this->_cache = FALSE;
					$this->setCache( $id, $this->get( $id ) );
					$this->_cache = TRUE;
				}
				return $attributes;
			default: trigger_error( 'Bad arguments count', E_USER_ERROR );
		}
	}

	/* Create a link between two resources
	$silo->link( $from, $to );
		Add a link from $from id to $to id
	$silo->link( $from, $to, $attribute );
		Add a qualified $attribute link from $from id to $to id
	*/
	public function link( $from, $to, $attribute=NULL ) {
		$from = $this->meta( $from );
		$to = $this->meta( $to );
		if( !is_array( $from ) || !is_array( $to ) )
			return FALSE;
		if( empty( $attribute ) )
			$attribute = $to['class'];
		$stmt = $this->prepare( 'replace-link' );
		$stmt->execute( array( $from['id'], $to['id'], $attribute ) );
		return TRUE;
	}

	/* Remove single or multiple links
	$silo->unlink( $from, $to );
		Remove single link from $from to $to
	$silo->unlink( $from, NULL );
		Remove all links from $from
	$silo->unlink( NULL, $to );
		Remove all links to $to
	$silo->unlink( $id );
		Remove all links from or to $id
	*/
	public function unlink( $from=NULL, $to=NULL ) {
		$nbArgs = func_num_args();
		switch( $nbArgs ) {
			case 1:
				// remove all links from and to this resource id
				list( $id ) = func_get_args();
				$this->unlink( $id, NULL );
				$this->unlink( NULL, $id );
				return TRUE;
			case 2:
				// SET
				list( $from, $to ) = func_get_args();
				$emptyFrom = empty( $from );
				$emptyTo = empty( $to );

				if( $emptyFrom && $emptyTo )
					return TRUE;

				if( $emptyFrom ) {
					$stmt = $this->prepare( 'delete-link-to' );
					$stmt->execute( array( $to ) );
					return TRUE;
				}

				if( $emptyTo ) {
					$stmt = $this->prepare( 'delete-link-from' );
					$stmt->execute( array( $from ) );
					return TRUE;
				}

				$stmt = $this->prepare( 'delete-link' );
				$stmt->execute( array( $from, $to ) );
				return TRUE;
			default: trigger_error( 'Bad arguments count', E_USER_ERROR );
		}
	}

	/* Get all links from $id, grouped by link attributes, or class if none
	*/
	public function from( $id, $get=FALSE ) {
		$stmt = $this->prepare( 'select-children' );
		$stmt->execute( array( $id ) );
		$data = array();
		while( $d = $stmt->fetch( PDO::FETCH_ASSOC ) )
			$data[$d['attribute']][] = ( $get === TRUE ? $this->get( $d['id_child'] ) : $d['id_child'] );
		return $data;
	}

	/* Get all links to $id, grouped by link attributes, or class if none
	*/
	public function to( $id, $get=FALSE ) {
		$stmt = $this->prepare( 'select-parents' );
		$stmt->execute( array( $id ) );
		$data = array();
		while( $d = $stmt->fetch( PDO::FETCH_ASSOC ) )
			$data[$d['attribute']][] = ( $get === TRUE ? $this->get( $d['id_parent'] ) : $d['id_parent'] );
		return $data;
	}


	public function search( $arg=array(), $get=FALSE, $links=FALSE, $getLinks=FALSE ) {

		$sql = array(
			'SELECT id',
			'FROM '.$this->prefix.'_meta',
			'LEFT JOIN '.$this->prefix.'_attribute USING ( id )',
		);

		if( isset( $arg['where'] ) )
			$sql[] = 'WHERE '.$arg['where'];

		$sql[] = 'GROUP BY id';

		if( isset( $arg['order'] ) ) {
			$sql = preg_filter('/^/', "\t", $sql);
			if( !is_array( $arg['order'] ) )
				$arg['order'] = array_map( 'trim', explode( ',', $arg['order'] ) );
			$arg['order'] = array_reverse( $arg['order'] );
			foreach( $arg['order'] as $order ) {
				$direction = 'DESC';
				$order = explode( ' ', $order );
				if( count( $order ) !== 2 || strtoupper( $order[1] ) !== 'DESC' )
					$direction = 'ASC';
				array_unshift( $sql
					, 'SELECT id'
					, 'FROM '.$this->prefix.'_attribute'
					, 'WHERE id IN ('
				);
				array_push( $sql
					, ')'
					, 'AND attribute='.$this->pdo->quote( $order[0] )
					, 'GROUP BY id'
					, 'ORDER BY value '.$direction
				);
			}
		}

		if( isset( $arg['limit'] ) )
			$sql[] = 'LIMIT '.$arg['limit'];

		$sql = implode( "\n", $sql );
		$sql = preg_replace( '#^SELECT#', 'SELECT SQL_CALC_FOUND_ROWS', $sql );
		$sql = "\n".$sql;

		if( $get === 'debug' )
			return array( $sql );

		$start = microtime( TRUE );

		$stmt = $this->pdo->query( $sql );
		$data = array();
		$data['total'] = $this->pdo->query( 'SELECT FOUND_ROWS()' )->fetch( PDO::FETCH_COLUMN );
		$data['results'] = array();
		while( $d = $stmt->fetch( PDO::FETCH_COLUMN, 0 ) )
			$data['results'][] = ( $get === TRUE ? $this->get( $d, $links, $getLinks ) : $d );

		$stop = microtime( TRUE );
		$data['duration'] = number_format( round( $stop - $start, 6 ), 6 );

		return $data;
	}

	public function filter( $field, $operator, $value ) {
		$sql = ' ';
		if( $field == 'id' || $field == 'class' ) {
			$sql = $field;
		} else {
			$sql = 'attribute='.$this->pdo->quote( $field ).' AND value';
		}

		$operator = strtoupper( $operator );
		switch( $operator ) {
			case '=':
			case '!=':
			case '<=':
			case '>=':
			case '<':
			case '>':
			case '<>':
				if( !is_string( $value ) )
					throw new SiloException( 'Bad Request', 400 );
				$sql .= $operator.$this->pdo->quote( $value );
				break;
			case 'LIKE':
				if( !is_string( $value ) )
					throw new SiloException( 'Bad Request', 400 );
				$sql .= ' '.$operator.' '.$this->pdo->quote( $value );
				break;
			case 'IN':
				$list = ( !is_array( $value ) ? explode( ',', $value ) : $value );
				foreach( $list as $k=>$v )
					$list[$k] = $this->pdo->quote( trim( $v ) );
				$sql .= ' IN ( '.implode( ', ', $list ).' )';
				break;
			default:
				throw new SiloException( 'Bad Request', 400 );
		}

		return $sql;
	}

	public function group() {
		$filters = func_get_args();
		if( count( $filters ) < 2 )
			throw new SiloException( 'Bad Request', 400 );

		$operator = strtoupper( trim( array_shift( $filters ) ) );
		if( $operator !== 'OR' && $operator !== 'AND' )
			throw new SiloException( 'Bad Request', 400 );

		$filters = ' ( '.implode( ' ) '.$operator.' ( ', $filters ).' )';
		return $filters;
	}


	public function emptyCache() {
		$stmt = $this->prepare( 'delete-all-cache' );
		$stmt->execute();
	}


	/* PRIVATE */

	/* Prepare a PDO statement and store it into an instance internal cache for further calls.
	Return PDO statement
	*/
	private function prepare( $stmtName ) {
		if( !array_key_exists( $stmtName, $this->stmt ) ) {
			$sql = self::$sql[$stmtName];
			$sql = str_replace( 'PREFIX', $this->prefix, $sql );
			$this->stmt[$stmtName] = $this->pdo->prepare( $sql );
		}
		return $this->stmt[$stmtName];
	}

	/* Fetch cached resource $id
	*/
	private function getCache( $id ) {
		switch( strtolower( $this->cacheType ) ) {
		case 'pdo':
			$stmt = $this->prepare( 'select-cache' );
			$stmt->execute( array( $id ) );
			$resource = $stmt->fetch( PDO::FETCH_COLUMN );
			break;
		case 'disk':
			$hash = hash( 'sha1', $id );
			$file = $this->calcFileCachePath( $hash );
			if( !is_file( $file ) )
				return FALSE;
			$resource = file_get_contents( $file );
			break;
		}
		return ( $resource ? json_decode( $resource, TRUE ) : FALSE );
	}

	/* Store or delete resource $id in cache
	*/
	private function setCache( $id, $resource=NULL ) {
		switch( strtolower( $this->cacheType ) ) {
		case 'pdo':
			if( is_null( $resource ) ) {
				$stmt = $this->prepare( 'delete-cache' );
				$stmt->execute( array( $id ) );
			} else {
				$stmt = $this->prepare( 'replace-cache' );
				$stmt->execute( array( $id, json_encode( $resource ) ) );
			}
			break;
		case 'disk':
			$hash = hash( 'sha1', $id );
			$file = $this->calcFileCachePath( $hash );
			$dir = dirname( $file );
			if( !is_dir( $dir ) ) {
				try {
					mkdir( $dir, 0777, TRUE );
				} catch( Exception $e ) {
					throw new SiloException( 'Can\'t create cache directory ('.$e->getMessage().')' );
				}
			}
			if( is_null( $resource ) ) {
				unlink( $resource );
			} else {
				$json = json_encode( $resource );
				file_put_contents( $file, $json );
			}
			break;
		}
		//echo 'setCache|';
	}

	/* Calculate the path to cached resource
	*/
	private function calcFileCachePath( $hash ) {
		return implode( '/', array(
			$this->cachePath,
			$this->prefix,
			substr( $hash, 0, 1 ),
			substr( $hash, 1, 1 ),
			$hash,
		) );
	}

}
