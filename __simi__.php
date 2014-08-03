<?php


/**
 * Base class for all models
 */
abstract class ModelBase {
    /**
     * Cache database results
     * @var array
     */
    protected static $cache = array();
    
    /**
     * Store model's attributes as associative array
     * @var array
     */
    protected $props;
    
    
    /**
     * Get record by id wrapped in a ModelBase
     * @param string $class
     * @param int $id
     * @return ModelBase
     */
    protected static function _id($class, $id) {
        if (isset(static::$cache[$class][$id]))
            return static::$cache[$class][$id];
        else {
            $record = System\Database\Query::table(Base::table($class))->where('id', '=', $id)->fetch();
            return $record? new static((array)$record) : null;
        }
    }
    
    /**
     * Get all records from table wrapped in a collection
     * @param string $class
     * @return ModelCollection
     */
    protected static function _listing($class) {
        if (!isset(static::$cache[$class])) {
            $result = System\Database\Query::table(Base::table($class))->get();
            $records = array();
            foreach ($result as $record)
                //index by id
                $records[$record->id] = new static($record);
            
            static::$cache[$class] = new ModelCollection($records);
        }
            
        return static::$cache[$class];
    }
    
    /**
     * Copy key - value pairs
     * @param array $props
     */
    public function __construct($props = array()) {
        $this->props = array();
        
        foreach ($props as $k => $v)
            $this->$k = $v;
    }

    public function __get($k) {
        return isset($this->$k)? $this->props[$k] : null;
    }
    
    public function __isset($k) {
        return array_key_exists($k, $this->props);
    }
    
    public function __set($k, $v) {
        $this->props[$k] = $v;
    }
    
    
    /**
     * Apply a function to the model
     * @param callable $function
     * @return mixed
     */
    public function apply($function) {
        if (is_callable($function))
            return call_user_func($function, $this);
    }

    /**
     * Apply a template to model
     * @param string $template
     * @return string
     */
    public function format($template) {
        foreach ($this->props as $k => $v)
            $template = str_replace('{{ '.$k.' }}', $v, $template);
        
        return $template;
    }

    /**
     * Get model's attribute
     * @param  string $name
     * @param  mixed $default
     * @return mixed
     */
    public function get($name, $default = '') {
        return isset($this->$name)? $this->$name : $default;
    }
    
    /**
     * Test prop againt value
     * @param string $k
     * @param string $op
     * @param mixed $v
     * @return boolean
     */
    public function test($k, $op, $v = null) {
        if (isset($this->$k)) {
            $k = $this->$k;
            
            //use '==' as default op, if not provided
            if (func_num_args() === 2)
                list($op, $v) = array('==', $op);

            //primitive comparison
            if (array_search($op, explode(',', '===,!==,==,!=,>,>=,<,<=')) !== false) {
                //cant do return eval();
                eval('$test = ($k '.$op.' $v);');
                return $test;
            }
        }
        return false;
    } 
}


/**
 * Model for articles
 * __constructor adds custom fields to model
 */
class ModelArticle extends ModelBase {
    protected static $records;


    public function __construct($props = array()) {        
        parent::__construct($props);
        
		$page = Registry::get('posts_page');
		$this->url = base_url($page->slug . '/' . $this->slug);
		
		//add custom fields
        foreach (Extend::fields('post', $this->id) as $field) {
            $key = $field->key;
            $type = $field->field;
            $value = $field->value;
            $this->$key = isset($value->$type)? $value->$type : '';
        }
    } 
    
    public static function id($id) {
        return parent::_id('posts', $id);
    }
    public static function listing() {
        return parent::_listing('posts');
    }
}


/**
 * Model for categories
 */
class ModelCategory extends ModelBase {
    protected static $records;
	
	public function __construct($props = array()) {
        parent::__construct($props);
		$this->url = base_url('category/' . $this->slug);
	}
	
    public static function id($id) {
        return parent::_id('categories', $id);
    }
    public static function listing() {
        return parent::_listing('categories');
    }
}


/**
 * Model for pages
 * __constructor adds custom fields to model
 */
class ModelPage extends ModelBase {
    
    public function __construct($props = array()) {
        parent::__construct($props);
        
        $this->url = base_url($this->slug);
        
        foreach (Extend::fields('page', $this->id) as $field) {
            $key = $field->key;
            $type = $field->field;
            $value = $field->value;
            $this->$key = isset($value->$type)? $value->$type : '';
        }
    }
    
    public static function id($id) {
        return parent::_id('pages', $id);
    }
    public static function listing() {
        return parent::_listing('pages');
    }
}


/**
 * Collection of models
 * provides API to access models' methods
 */
class ModelCollection implements Iterator, Countable, ArrayAccess {
    protected $records;
    
    public function __construct($records) {
        foreach ($records as $key => $record)
            $this->records[$key] = $record;
    }
    
    /**
     * Shortcut to filter($name, '==', $val)
     * @param string $name
     * @param array $arguments
     * @return ModelCollection
     */
    public function __call($name, $arguments) {
        array_unshift($arguments, $name);
        return call_user_func_array(array($this, 'filter'), $arguments);
    }

    /**
     * Shortcut to first()->$name
     * @param  string $name
     * @return string
     */
    public function __get($name) {
        $first = $this->first();
        return $first? $first->$name : '';
    }

    /**
     * Shortcut to each($model->$name = $value)
     * @param string $name
     * @param mixed $value
     */
    public function __set($name, $value) {
        $this->each(function(ModelBase $model) use ($name, $value) {
            $model->$name = $value;
        });
    }

        /**
     * Apply function to every model
     * @param callable $function
     * @return ModelCollection
     */
    public function each($function) {
        if (is_callable($function))
            array_walk($this->records, $function);
        
        return $this;
    }

    /**
     * Filter records based on filter function or by key - op - value comparison
     * @param mixed $name filter function or attribute name
     * @param string $op
     * @param mixed $value
     * @return ModelCollection
     */
    public function filter($name, $op = null, $value = null) {
        if (isCallback($name))
            $this->records = array_filter($this->records, $name);
        else $this->records = array_filter($this->records, function(ModelBase $model) use ($name, $op, $value) {
            return $model->test($name, $op, $value);
        });
        
        return $this;
    }
	
	/**
	 * Get first model in collection
	 * @return ModelBase
	 */
	public function first() {
		if (!empty($this->records)) {
			$first = array_shift($this->records);
			$this->records[$first->id] = $first;
			return $first;
		}
	}
    
    /**
     * Apply a template to the model
     * @param string $template
     * @param string $glue
     * @return string the joined templates
     */
    public function format($template, $glue = "\n") {
        return join($glue, array_map(function(ModelBase $model) use ($template) {
            return $model->format($template);
        }, $this->records));
    }

    /**
     * Get last record
     * @return ModelBase
     */
    public function last() {
        if (!empty($this->records)) {
            $last = array_pop($this->records);
            $this->records[$last->id] = $last;
            return $last;
         }
    }
    
    /**
     * Limit the number of models
     * @param int $num
     * @return ModelCollection
     */
    public function limit($num) {
        $this->records = array_slice($this->records, 0, $num, true);
        return $this;
    }

    /**
     * Maps the records by a function or returns an array of mapped attribute
     * @param mixed $function map function or attribute name
     * @return array
     */
    public function map($function) {
        if (isCallback($function))
            return array_map($function, $this->records);
        else return $this->map(function($model) use ($function) {
            return $model->$function;
        });
    }
    
    /**
     * Reverse the order of models
     * @return ModelCollection
     */
    public function reverse() {
        $this->records = array_reverse($this->records);
        return $this;
    }

    /**
     * Sort models by function or attribute
     * @param mixed $cmp sort function or attribute name
     * @param boolean $reverse weather to sort ascending or descending
     * @return ModelCollection
     */
    public function sort($cmp, $reverse = false) {
        if (isCallback($cmp))
            usort($this->records, $cmp);
        else $this->sort(function($a, $b) use ($cmp) {
            return strcmp($a->$cmp, $b->$cmp);
        });
        
        $reverse && $this->reverse();
        
        return $this;
    }

    
    //Countable interface
    public function count() {
        return count($this->records);
    }
    
    //Iterator interface
    public function current() {
        return current($this->records);
    }

    public function key() {
        return key($this->records);
    }

    public function next() {
        return next($this->records);
    }

    public function rewind() {
        reset($this->records);
    }

    public function valid() {
        return current($this->records);
    }

    //ArrayAccess interface
    public function offsetExists($offset) {
        return array_key_exists($offset, $this->records);
    }

    public function offsetGet($offset) {
        return isset($this[$offset])? $this->records[$offset] : null;
    }

    public function offsetSet($offset, $value) {
        //cant set
    }

    public function offsetUnset($offset) {
        unset($this->records[$offset]);
    }
}


function isCallback($o) {
	return $o instanceof Closure;
}

/**
 * Shortcut to ModelArticle methods
 * @param int $id
 * @return mixed
 */
function articles($id = -1) {
    return $id >= 0? ModelArticle::id($id) : ModelArticle::listing();
}

/**
 * Shortcut to ModelCategory methods
 * @param int $id
 * @return mixed
 */
function categoryes($id = -1) {
    return $id >= 0? ModelCategory::id($id) : ModelCategory::listing();
}

/**
 * Shortcut to ModelPage methods
 * @param int $id
 * @return mixed
 */
function pages($id = -1) {
    return $id >= 0? ModelPage::id($id) : ModelPage::listing();
}

/**
 * Check wheater on category page
 * @return boolean
 */
function is_category() {
	return strpos(current_url(), 'category/') === 0;
}

/**
 * @return string
 */
function current_category_slug() {
	return is_category()? str_replace('category/', '', current_url()) : '';
}

function posts_page_url() {
	return base_url(Registry::prop('posts_page', 'slug'));
}
