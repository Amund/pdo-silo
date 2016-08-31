<a name="top"></a>
# PDO-Silo

A silo to store and link resources, via a simple api.

Type|Methods
----:|:----
Silo | [`__construct`](#method-construct), [`create`](#method-create), [`delete`](#method-delete)
Resources | [`get`](#method-get), [`set`](#method-set), [`meta`](#method-meta), [`attr`](#method-attr), [`attributes`](#method-attributes)
Links | [`link`](#method-link), [`unlink`](#method-unlink), [`from`](#method-from), [`to`](#method-to)
Searches | [`search`](#method-search), [`filter`](#method-filter), [`group`](#method-group)
Cache | [`emptyCache`](#method-emptyCache)

**Dowload** : https://github.com/Amund/pdo-silo


<a name="silo"></a>
## Silo [^](#top)

The idea behind the Silo PHP class is to get a minimal class to have a persistence layer in a project, a nano ORM and DBAL (Doctrine ?). Sure, it doesn't fit in all projects, it has a bunch of limitations, but it's simple, small, fast, and more importantly, it's fun to use.

A quick overview ? Here it is.

	// creating a testing silo
	$pdo = new PDO( 'mysql:host=localhost;dbname=mydb;charset=utf8','login','password' );
	$silo = new Silo( $pdo, 'test' );
	$silo->create();

	// adding resources
	$silo->set( 'person', array( 'firstname'=>'John', 'lastname'=>'Doe', 'gender'=>'M' ) ); // => 1
	$silo->set( 'person', array( 'firstname'=>'Cynthia', 'lastname'=>'Doe', 'gender'=>'F' ) ); // => 2
	$silo->set( 'person', array( 'firstname'=>'Régis', 'lastname'=>'Doe', 'gender'=>'M' ) ); // => 3
	$silo->set( 'address', array( 'street'=>'5 Bedford St', 'city'=>'New York' ) ); // => 4
	$silo->set( 'animal', array( 'type'=>'fish', 'species'=>'Lutjanus sebae', 'color'=>'blue' ) ); // => 5

	// modifying resource
	$silo->attr( 4, 'zip', '10118');
	$silo->attr( 5, 'color', 'red' );

	// getting a resource by its id
	$resource = $silo->get( 1 );
	// => array(
	//	'id'=>1,
	//	'class'=>'person',
	//	'firstname'=>'John',
	//	'lastname'=>'Doe'
	// )

	// adding some links
	$silo->link( 1, 2, 'husband' );
	$silo->link( 2, 1, 'wife' );
	$silo->link( 3, 1, 'son' );
	$silo->link( 3, 2, 'son' );
	$silo->link( 4, 1 );
	$silo->link( 4, 2 );
	$silo->link( 4, 3 );
	$silo->link( 5, 3, 'pet' );

	// searching resources
	$silo->search( array(
		'where'=> $silo->filter( 'lastname', 'LIKE', 'doe' )
	) ); // => array( 1, 2, 3 )

	$silo->search( array(
		'where'=> $silo->group(
			'and',
			$silo->filter( 'class', '=', 'person' ),
			$silo->filter( 'lastname', 'LIKE', 'doe' ),
			$silo->filter( 'gender', '=', 'M' )
		)
	) ); // => array( 1, 3 );

All datas in NOOP are stored in a unique multilevel associative array, its registry system. It's globally accessible without polluting global scope, and organized as follow :
- `config` All configuration variables in there
- `app` App related infos, calculated from the request
- `request` Details of the request, and controller related vars
- `controllers` Collection of PHP scripts to include
- `pdo` Collection of PDO instances already created
- `benchmark` Collection of benchmarks
- `var` Your playground, store anything you want...


During the development, you can inspect this registry at any time with the method [`inspect`](#method-inspect).

	// Inspect all the registry...
	echo noop::inspect();

    // ...or a part...
	echo noop::inspect( 'config' );

    // ...or a part of a part...
	echo noop::inspect( 'config/path' );


<a name="api"></a>
## API [^](#top)


<a name="method-construct"></a>
### __construct( `$pdo`[, `$prefix`[, `$cache`]] ) [^](#top)

Create a Silo instance to play with. The PDO resource must be a valid MySQL or Sqlite resource (for now).

###### Parameters
- `$pdo` Required, PDO resource.
- `$prefix` Optional, String, default to 'resource'. This is the database tables prefix.
- `$cache` Optional, String, default to NULL. Two cache systems are available : PDO or disk. By default, the caching system use $pdo resource to store cache items, in a table named "`$prefix`_cache". Otherwise, you can set caching system to disk by giving a path.

###### Return
- Return the Silo instance.

###### Example

	// create a mysql PDO resource
	$pdo = new PDO( 'mysql:host=localhost;dbname=mydb;charset=utf8','login','password' );
	// or for sqlite
	$pdo = new PDO( 'sqlite:/path/to/database.db' );

	// then your silo instance, with 'resource' as prefix, and a database cache
	$silo = new Silo( $pdo );

	// or with a different prefix
	$silo = new Silo( $pdo, 'myproject' );

	// or with disk cache
	$silo = new Silo( $pdo, 'myproject', '/path/to/cache' );


## TODO
- Continue documentation
- Add tests to cache system
- Add tests to search methods
- Extend to (or maybe just test) PDO resources other than MySQL or Sqlite
- Perhaps modify cache implementation to follow [PRSR6](http://www.php-fig.org/psr/psr-6/)
