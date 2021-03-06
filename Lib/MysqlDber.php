<?php
class MysqlDber
{
	/**
	 * 链接游戏库的引擎
	 * @var	MysqlDb
	 */
	protected $dbEngine;
	
	/**
	 * 用户ID
	 * @var	int
	 */
	protected $userId;
	
	/**
	 * 所有游戏库的配置
	 * @var	array
	 */
	protected static $dbConfigs = null;
	
	/**
	 * 索引数据库引擎
	 * @var	MysqlDb
	 */
	protected static $indexDbEngine = null;
	
	/**
	 * 游戏数据库引擎
	 * @var	MysqlDb[]
	 */
	protected static $dbEngines = array();
	
	/**
	 * 获取数据库单例
	 * @param	int $userId	用户ID
	 * @return	iDber
	 */
	public static function & getInstance( $userId )
	{
		static $dbObject = array();
		if( !isset( $dbObject[$userId] ) )
		{
			$dbObject[$userId] = new MysqlDber( $userId );
		}
		
		return $dbObject[$userId];
	}
	
	/**
	 * 实例化数据库类
	 * @param	int	$userId	用户ID
	 */
	protected function __construct( $userId )
	{
		$this->userId = (integer)$userId;
		$this->dbEngine = self::initDb( $this->getUserDbId() );
	}
	
	/**
	 * 初始化数据库
	 * @param	int $dbId	数据库ID
	 */
	protected static function initDb( $dbId )
	{
		if( !isset( self::$dbEngines[$dbId] ) )
		{
			$dbConfigs = self::getDbConfigs();
			self::$dbEngines[$dbId] = new MysqlDb( $dbConfigs[$dbId] );
		}
		return self::$dbEngines[$dbId];
	}
	
	/**
	 * 获取用户所在数据库ID
	 * @return	integer
	 */
	protected function getUserDbId()
	{
		$indexCache = & Common::getCache( 'index' );
		$dbId = $indexCache->get( $this->userId .'_gamedbId' );
	
		if( $dbId === false )
		{
			$result = self::getIndexDbEngine()->fetchOneAssoc( 'SELECT `db_id` AS `dbId` FROM `index_0` WHERE `userid` = '. $this->userId );
			if( empty( $result ) )
			{
				$dbId = $this->allocateDbForUser();

			}
			else
			{
				$dbId = $result['dbId'];
			}
			
			$indexCache->set( $this->userId .'_gamedbId' , $dbId );
		}
		return $dbId;
	}
	
	/**
	 * 分配一个Db给用户
	 * @return	integer
	 */
	protected function allocateDbForUser()
	{
		$canUseDbConfigs = self::getCanUseDbConfig();
		$dbId = array_rand( $canUseDbConfigs );
		$sql =  "INSERT INTO `index_0` ( `userid` , `db_id` ) VALUES ( {$this->userId} , {$dbId} ) " ;
		if( self::getIndexDbEngine()->query( $sql ) == false )
		{
			throw new Exception( "The new user could not create index data.\n" , 6 );
		}
		return $dbId;
	}
	
	/**
	 * 获取可使用的数据库
	 * @return	array
	 */
	protected static function getCanUseDbConfig()
	{
		$canUseDbConfigs = self::getDbConfigs( true );

		if( empty( $canUseDbConfigs ) )
		{
			$canUseDbConfigs = self::getDbConfigs();
		}
		
		if( empty( $canUseDbConfigs ) )
		{
			throw new Exception( "Not have database can use.\n" , 6 );
		}
		
		return $canUseDbConfigs;
	}
	
	/**
	 * 获取所有数据库配置
	 * @param	boolean $isOnlyNotFull	是否只返回未满的数据库
	 */
	protected static function getDbConfigs( $isOnlyNotFull = false )
	{
		if( self::$dbConfigs === null )
		{
			$dbConfigs = Common::getCache( 'index' )->get( 'gameDBConfigs' );
			if( $dbConfigs == false )
			{
			
				$dbConfigs = self::getDbConfigFromDb();
				
				
				Common::getCache( 'index' )->set( 'gameDBConfigs' , $dbConfigs , 3600 );
			}
			
			self::$dbConfigs = $dbConfigs;
		}
			
		if( !$isOnlyNotFull )
		{
			return self::$dbConfigs;
		}
		
		return self::filterFullDb();
	}
	
	/**
	 * 从数据库中获取数据库配置
	 * @return	array
	 */
	protected static function getDbConfigFromDb()
	{
		$dbConfigs = array();
		$result = self::getIndexDbEngine()->fetchArray( 'SELECT `id` AS `dbId` , `is_full` AS `isFull` , `master_ip` AS `host` , `master_port` AS `port` , `username` AS `user` , `pwd` AS `passwd` , `db_name` AS `name` FROM `db_config`' );
		
		if( empty( $result ) )
		{
			throw new Exception( "Not have database can use.\n" , 6 );
		}
		
		foreach( $result as $dbConfig )
		{
			$dbConfigs[$dbConfig['dbId']] = $dbConfig;
		}
		return $dbConfigs;
	}

	/**
	 * 过滤已经满数据库
	 * @return	array
	 */
	protected static function filterFullDb()
	{
		$notFullDbConfigs = array();
		foreach( self::$dbConfigs as $dbId => $dbConfig )
		{
			if( !$dbConfig['isFull'] )
			{
				$notFullDbConfigs[$dbId] = $dbConfig;
			}
		}
		return $notFullDbConfigs;
	}
	
	/**
	 * 获取索引数据库引擎
	 * @return	MysqlDb
	 */
	protected static function getIndexDbEngine()
	{
		if( self::$indexDbEngine === null )
		{
			$dbConfig = & Common::getConfig( 'mysqlDb' );
			self::$indexDbEngine = new MysqlDb( $dbConfig['dbIndex'] );
		}
		return self::$indexDbEngine;
	}
	
	/**
	 * 数据新增接口
	 * @param	string $tableName		数据表名
	 * @param	array $value			数据
	 * @param	array $condition		条件:array( 'id' => 1 )
	 * @return	boolean
	 */
	public function add( $tableName , $value , $condition = array() )
	{
		$value = $value + $condition;
		$value['uid'] = $this->userId;
		$keys = array_keys( $value );
		$sql = "INSERT INTO `{$tableName}` (`" . implode( "` , `" , $keys ) . "`) VALUES (\"" . implode( '" , "' , $value ) . "\")";

		$rs = $this->dbEngine->query( $sql );
		if( $rs == false )
		{
			$errSqlLog = new ErrorLog( "mysql" );
			$errSqlLog->addLog( $sql );
			throw new GameException( GameException::DB_SQL_ERROR );
		}
		return $this->dbEngine->affectedRows();
	}
	
	/**
	 * 数据修改接口
	 * @param	string $tableName		数据表名
	 * @param	array $value			数据
	 * @param	array $condition		条件:array( 'id' => 1 )
	 * @return	boolean
	 */
	public function update( $tableName , $value , $condition = array() )
	{
		$sql = "UPDATE `{$tableName}` SET ";
		foreach ( $value as $key => $item )
		{
			$sql .= "`{$key}` = '{$item}',";
		}
		
		$sql .= "`uid` = {$this->userId} WHERE `uid` = {$this->userId}";
	
		foreach ( $condition as $key => $item )
		{
			$sql .= " AND `{$key}` = '{$item}'";   
		}
		
		$rs = $this->dbEngine->query( $sql );
		if( $rs == false )
		{
			$errSqlLog = new ErrorLog( "mysql" );
			$errSqlLog->addLog( $sql );
			throw new GameException( GameException::DB_SQL_ERROR );
		}
		
		return $this->dbEngine->affectedRows();
	}
	
	/**
	 * 数据删除接口
	 * @param	string $tableName		数据表名
	 * @param	array $condition		条件:array( 'id' => 1 )
	 * @return	boolean
	 */
	public function delete( $tableName , $condition = array() )
	{
		$sql = "DELETE FROM `{$tableName}` WHERE `uid` = {$this->userId}";
		foreach ( $condition as $key => $item )
		{
			$sql .= " AND `{$key}` = '{$item}'";   
		}
	
		//echo $sql;
		$rs = $this->dbEngine->query( $sql );
		if( $rs == false )
		{
			$errSqlLog = new ErrorLog( "mysql" );
			$errSqlLog->addLog( $sql );
			throw new GameException( GameException::DB_SQL_ERROR );
		}
		return $this->dbEngine->affectedRows();
	}
	
	/**
	 * 数据单项查询接口(只能根据用户ID查询)
	 * @param	string $tableName		数据表名
	 * @param	array $value			数据
	 * @return	array
	 */
	public function find( $tableName )
	{
		$sql = "SELECT * FROM `{$tableName}` WHERE `uid` = {$this->userId} LIMIT 1";
	//	$rs = $this->dbEngine->fetchOneAssoc( $sql );
		return $this->dbEngine->fetchOneAssoc( $sql );
	}
	
	
	/**
	 * 数据多项查询接口
	 *
	 * @param	string $tableName		数据表名
	 * @param	array $returnItems		需要的字段
	 * @return	array
	 */
	public function findAll( $tableName , $returnItems )
	{
		$sql = "SELECT * FROM `{$tableName}` WHERE `uid` = {$this->userId}";
		return $this->dbEngine->fetchArray( $sql );
	}
	
	/**
	 * 全局数据ID获取接口
	 *
	 * @param	string $tableName		数据表名
	 * @return	int
	 */
//	public function getID( $tableName )
//	{
//		return $this->dbEngine->get_id( $tableName );
//	}
	
	/**
	 * 对所有游戏库执行语句
	 * @param	string $sql	SQL语句
	 */
	public static function fetchAllDatabase( $sql )
	{
		self::initAllDbEngine();
		$result = array();
		foreach( self::$dbEngines as $dbId => $dbEngine )
		{
			$result[$dbId] = $dbEngine->fetchArray( $sql );	
		}
		return $result;
	}
	/**
	 * 对单个库执行语句
	 * @param string $sql
	 * @param int $id
	 */
	public static function fetchOneDatabase( $sql , $id )
	{
		self::initAllDbEngine( $id );
		$result = array();
		foreach( self::$dbEngines as $dbId => $dbEngine )
		{
			if( $id > 0  && $dbId == $id )
			{
				$result = $dbEngine->fetchArray( $sql );
			}
		}
		return $result;
	}
	
	/**
	 * 初始化所有数据库
	 */
	protected static function initAllDbEngine( $id = 0 )
	{
		$dbConfigs = self::getDbConfigs();
		foreach( $dbConfigs as $dbId => $dbConfig )
		{
			if( !isset( self::$dbEngines[$dbId] ) )
			{
				if( ( $id > 0 && $dbId == $id )   ||  $id == 0 )
				{
					self::$dbEngines[$dbId] = new MysqlDb( $dbConfig );
				}
			}
		}
	}
	
	
	
	
	
	
}
