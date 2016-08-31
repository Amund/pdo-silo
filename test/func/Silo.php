<?php

namespace test\func;
require_once __DIR__ . '/../../src/Silo.php';

use atoum,
	PDO,
	Silo as SiloClass;


/**
 * @namespace test\func
 */
class Silo extends atoum {

	function createSilo() {
		$silo = new SiloClass( $this->pdo(), 'test' );
		$silo->create();
		return $silo;
	}

	function createSiloWithoutCache() {
		$silo = $this->createSilo();
		$silo->cache = FALSE;
		return $silo;
	}

	function tablesNames( $prefix='resource' ) {
		return array(
			$prefix.'_meta',
			$prefix.'_attribute',
			$prefix.'_link',
			$prefix.'_cache',
		);
	}

	function listAllTables( $pdo ) {
		return $pdo
			->query( 'SELECT name FROM sqlite_master WHERE type="table"' )
			->fetchAll( PDO::FETCH_COLUMN );
	}

	function pdo() {
		$pdo = new PDO( 'sqlite::memory:' );
		$pdo->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		return $pdo;
	}


	function testPdo() {
		$pdo = $this->pdo();
		$this->object( $pdo )->isInstanceOf( 'PDO' );
	}

	function testCreateDestroy() {
		$pdo = $this->pdo();
		$silo = new SiloClass( $pdo );

		$silo->create();
		$this
			->array( $this->listAllTables( $pdo ) )
			->containsValues( $this->tablesNames() );

		$silo->destroy();
		$this
			->array( $this->listAllTables( $pdo ) )
			->notContainsValues( $this->tablesNames() );
	}

	function testCreateDestroyWithPrefix() {
		$prefix = 'my_test_silo';
		$pdo = $this->pdo();
		$silo = new SiloClass( $pdo, $prefix );

		$silo->create();
		$this
			->array( $this->listAllTables( $pdo ) )
			->containsValues( $this->tablesNames( $prefix ) );

		$silo->destroy();
		$this
			->array( $this->listAllTables( $pdo ) )
			->notContainsValues( $this->tablesNames( $prefix ) );
	}

	function testMeta() {
		$silo = $this->createSiloWithoutCache();

		// add a new resource meta
		$id = $silo->meta( '', 'test1' );
		$this->integer( $id )->isEqualTo( 1 );

		// add another new resource meta
		$id = $silo->meta( NULL, 'test2' );
		$this->integer( $id )->isEqualTo( 2 );

		// add another new resource meta
		$id = $silo->meta( FALSE, 'test3' );
		$this->integer( $id )->isEqualTo( 3 );

		// add another new resource meta
		$id = $silo->meta( 0, 'test4' );
		$this->integer( $id )->isEqualTo( 4 );

		// get a non-existent resource meta
		$meta = $silo->meta( 5 );
		$this->boolean( $meta )->isFalse();

		// add a new resource meta with id 5
		$id = $silo->meta( 5, 'test5' );
		$this->integer( $id )->isEqualTo( 5 );

		// retrieve resource meta
		$meta = $silo->meta( 1 );
		$this->array( $meta )->isEqualTo( array( 'id'=>1, 'class'=>'test1' ) );

		// change resource meta class
		$silo->meta( 1, 'A brand new class of my own' );
		$meta = $silo->meta( 1 );
		$this->array( $meta )->isEqualTo( array( 'id'=>1, 'class'=>'A brand new class of my own' ) );
	}

	function testMetaErrors() {
		$silo = $this->createSilo();

		// bad arguments count (1 or 2)
		$silo->meta();
		$this->error()->exists();

		// bad id on the setter
		$silo->meta( 'bad id', 'test' );
		$this->error()->exists();
	}

	function testAttr() {
		$silo = $this->createSiloWithoutCache();
		$id = $silo->meta( '', 'test' );

		// add attributes to resource
		$silo->attr( $id, 'attr1', 'val1' );
		$silo->attr( $id, 'attr2', 'val2' );
		$silo->attr( $id, 'attr3', 'val3' );
		$silo->attr( $id, 'attr4', 'val4' );

		// retrieve attribute from resource
		$value = $silo->attr( $id, 'attr1' );
		$this->string( $value )->isEqualTo( 'val1' );

		// update attribute value
		$silo->attr( $id, 'attr1', 'modified' );
		$this->string( $silo->attr( $id, 'attr1' ) )->isEqualTo( 'modified' );

		// retrieve non-existent attribute from resource
		$value = $silo->attr( $id, 'attr5' );
		$this->variable( $value )->isNull();

		// remove attributes from resource (or setting it to empty value)
		$silo->attr( $id, 'attr1', '' );
		$silo->attr( $id, 'attr2', 0 );
		$silo->attr( $id, 'attr3', FALSE );
		$silo->attr( $id, 'attr4', NULL );
		$this->variable( $silo->attr( $id, 'attr1' ) )->isNull();
		$this->variable( $silo->attr( $id, 'attr2' ) )->isNull();
		$this->variable( $silo->attr( $id, 'attr3' ) )->isNull();
		$this->variable( $silo->attr( $id, 'attr4' ) )->isNull();

		// reserved attributes that can't be overriden
		$reserved = array( 'id', 'class', 'links', 'ID', 'Id');
		foreach( $reserved as $attr ) {
			$value = $silo->attr( $id, $attr, 'value' );
			$this->variable( $value )->isNull();
		}

	}

	function testAttrErrors() {
		$silo = $this->createSiloWithoutCache();
		$id = $silo->meta( '', 'test' );

		// bad arguments count (2 or 3)
		$silo->attr();
		$this->error()->exists();

		// setting a non-scalar value to attribute
		$silo->attr( $id, 'attr1', array( 'value' ) );
		$this->error()->exists();
	}

	function testAtributes() {
		$silo = $this->createSiloWithoutCache();
		$id = $silo->meta( '', 'test' );
		$attr = array(
			'attr1'=>'value1',
			'attr2'=>'value2',
		);

		// retrieve current attributes from resource
		$this->array( $silo->attributes( $id ) )->isEmpty();

		// set new attributes to resource
		$this->array( $silo->attributes( $id, $attr ) )->isEqualTo( $attr );

		// retrieve new attributes from resource
		$this->array( $silo->attributes( $id ) )->isEqualTo( $attr );

		// deleting all attributes (except reserved 'id','class' and 'links')
		$this->variable( $silo->attributes( $id, NULL ) )->isNull();
	}

	function testAttributesErrors() {
		$silo = $this->createSiloWithoutCache();
		$id = $silo->meta( '', 'test' );

		// bad arguments count (1 or 2)
		$silo->attr();
		$this->error()->exists();

		// setting a non-array or empty value to attributes
		$silo->attributes( $id, 'attr1', 'test' );
		$this->error()->exists();
	}

	function testLink() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2
		$silo->meta( '', 'c' ); // => 3

		// create links
		$this->boolean( $silo->link( 2, 1 ) )->isTrue();
		$this->boolean( $silo->link( 3, 1 ) )->isTrue();

		// get links
		$this
			->array( $silo->to( 1 ) )
			->isEqualTo( array( 'a'=>array( 2, 3 ) ) );
		$this
			->array( $silo->from( 2 ) )
			->isEqualTo( array( 'a'=>array( 1 ) ) );
	}

	function testLinkWithAttribute() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2
		$silo->meta( '', 'c' ); // => 3

		// create links with attribute
		$this->boolean( $silo->link( 2, 1, 'tag' ) )->isTrue();
		$this->boolean( $silo->link( 3, 1, 'tag' ) )->isTrue();

		// get links
		$this
			->array( $silo->to( 1 ) )
			->isEqualTo( array( 'tag'=>array( 2, 3 ) ) );
		$this
			->array( $silo->from( 2 ) )
			->isEqualTo( array( 'tag'=>array( 1 ) ) );
	}

	function testUnlinkSingle() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2

		$silo->link( 2, 1 );
		$silo->unlink( 2, 1 );

		$this->array( $silo->to( 1 ) )->isEmpty();
	}

	function testUnlinkFrom() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2
		$silo->meta( '', 'c' ); // => 3

		$silo->link( 1, 2 );
		$silo->link( 1, 3 );
		$silo->unlink( 1, NULL );

		$this->array( $silo->from( 1 ) )->isEmpty();
	}

	function testUnlinkTo() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2
		$silo->meta( '', 'c' ); // => 3

		$silo->link( 2, 1 );
		$silo->link( 3, 1 );
		$silo->unlink( NULL, 1 );

		$this->array( $silo->to( 1 ) )->isEmpty();
	}

	function testUnlinkFromAndTo() {
		$silo = $this->createSiloWithoutCache();
		$silo->meta( '', 'a' ); // => 1
		$silo->meta( '', 'b' ); // => 2
		$silo->meta( '', 'c' ); // => 3

		$silo->link( 2, 1 );
		$silo->link( 3, 2 );
		$silo->unlink( 2 );

		$this->array( $silo->from( 2 ) )->isEmpty();
		$this->array( $silo->to( 2 ) )->isEmpty();
	}

	function testUnlinkErrors() {
		$silo = $this->createSiloWithoutCache();

		// bad arguments count (1 or 2)
		$silo->unlink();
		$this->error()->exists();
	}

	function testGetSet() {
		$silo = $this->createSiloWithoutCache();
		$persons = array(
			array( 'name'=>'John Doe' ),
			array( 'name'=>'Cynthia Doe' ),
			array( 'name'=>'Régis Doe' ),
		);

		// set resources...
		$i = 1;
		foreach( $persons as $person ) {
			$this
				->integer( $silo->set( 'person', $person ) )
				->isEqualTo( $i );
			$i++;
		}

		// ...add some links...
		$silo->link( 1, 2, 'husband' );
		$silo->link( 2, 1, 'wife' );
		$silo->link( 3, 1, 'son' );
		$silo->link( 3, 2, 'son' );

		// ...then get resources
		$this->array( $silo->get( 1 ) )->isEqualTo( array(
			'id'=>1,
			'class'=>'person',
			'name'=>'John Doe',
		) );

		// get resources with links id
		$this->array( $silo->get( 1, TRUE ) )->isEqualTo( array(
			'id'=>1,
			'class'=>'person',
			'name'=>'John Doe',
			'links'=> array(
				'from'=> array(
					'wife'=> array( 2 ),
					'son'=> array( 3 ),
				),
				'to'=> array(
					'husband'=> array( 2 ),
				),
			)
		) );

		// get resource and all linked resources
		$this->array( $silo->get( 1, TRUE, TRUE ) )->isEqualTo( array(
			'id'=>1,
			'class'=>'person',
			'name'=>'John Doe',
			'links'=> array(
				'from'=> array(
					'wife'=> array(
						array(
							'id'=>2,
							'class'=>'person',
							'name'=>'Cynthia Doe',
						)
					),
					'son'=> array(
						array(
							'id'=>3,
							'class'=>'person',
							'name'=>'Régis Doe',
						)
					),
				),
				'to'=> array(
					'husband'=> array(
						array(
							'id'=>2,
							'class'=>'person',
							'name'=>'Cynthia Doe',
						)
					),
				),
			)
		) );
	}

}
