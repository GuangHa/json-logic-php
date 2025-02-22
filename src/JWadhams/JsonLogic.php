<?php

namespace JWadhams;

class JsonLogic
{
    private static $custom_operations = [];
    public static function get_operator($logic)
    {
        return array_keys($logic)[0];
    }
    public static function get_values($logic, $fix_unary = true)
    {
        $op = static::get_operator($logic);
        $values = $logic[$op];

        //easy syntax for unary operators, like ["var" => "x"] instead of strict ["var" => ["x"]]
        if ($fix_unary and (!is_array($values) or static::is_logic($values))) {
            $values = [ $values ];
        }
        return $values;
    }

    public static function is_logic($array)
    {
        return (
            is_array($array)
            and
            count($array) === 1
            and
            is_string(static::get_operator($array))
        );
    }

    public static function truthy($logic)
    {
        if ($logic === "0") {
            return true;
        }
        return (bool)$logic;
    }

    public static function apply($logic = [], $data = [], $cumulative = false, $originalData = [])
    {
        //I'd rather work with array syntax
        if (is_object($logic)) {
            $logic = (array)$logic;
        }

        if (! self::is_logic($logic)) {
            if (is_array($logic)) {
                if ($cumulative) {
                    $modifiedData = $data;
                    $count = 0;
                    foreach($logic as $rule) {
                        $modifiedData = self::apply($rule, $modifiedData, false, $originalData);
                        $count++;
                    }
                    return $modifiedData;
                }
                //Could be an array of logic statements. Only one way to find out.
                return array_map(function ($l) use ($data, $originalData) {
                    return self::apply($l, $data, false, $originalData);
                }, $logic);
            } else {
                return $logic;
            }
        }

        $operators = [
            '==' => function ($a, $b) {
                return $a == $b;
            },
            '===' => function ($a, $b) {
                return $a === $b;
            },
            '!=' => function ($a, $b) {
                return $a != $b;
            },
            '!==' => function ($a, $b) {
                return $a !== $b;
            },
            '>' => function ($a, $b) {
                return $a > $b;
            },
            '>=' => function ($a, $b) {
                return $a >= $b;
            },
            '<' => function ($a, $b, $c = null) {
                if ($c === null) {
                    return $a < $b;
                }
                return  ($a < $b) and ($b < $c) ;
            },
            '<=' => function ($a, $b, $c = null) {
                if ($c === null) {
                    return  $a <= $b;
                }
                return ($a <= $b) and ($b <= $c) ;
            },
            '%' => function ($a, $b) {
                return $a % $b;
            },
            '!!' => function ($a) {
                return static::truthy($a);
            },
            '!' => function ($a) {
                return ! static::truthy($a);
            },
            'and' => function () {
                foreach (func_get_args() as $a) {
                    if (! static::truthy($a)) {
                        return $a;
                    }
                }
                return $a;
            },
            'or' => function () {
                foreach (func_get_args() as $a) {
                    if (static::truthy($a)) {
                        return $a;
                    }
                }
                return $a;
            },
            'log' => function ($a) {
                error_log($a);
                return $a;
            },
            'var' => function ($a = null, $default = null, $useOriginalData = false, $newData = []) use ($data, $originalData) {
                if ($useOriginalData) {
                    $data = $originalData;
                }
                if (!empty($newData)) {
                    $data = $newData;
                }
                if ($a === null or $a === "") {
                    return $data;
                }

                //Descending into data using dot-notation
                //This is actually safe for integer indexes, PHP treats $a["1"] exactly like $a[1]
                foreach (explode('.', $a) as $prop) {
                    if ((is_array($data) || $data instanceof \ArrayAccess) && isset($data[$prop])) {
                        $data = $data[$prop];
                    } elseif (is_object($data) && isset($data->{$prop})) {
                        $data = $data->{$prop};
                    } else {
                        return $default; //Trying to get a value from a primitive
                    }
                }
                return $data;
            },
            'missing' => function () use ($data) {
                /*
                Missing can receive many keys as many arguments, like {"missing:[1,2]}
                Missing can also receive *one* argument that is an array of keys,
                which typically happens if it's actually acting on the output of another command
                (like IF or MERGE)
                */
                if (is_bool(func_get_arg(0)) && func_num_args() > 1) {
                    $data = func_get_arg(1);
                    $values = func_get_arg(2);
                } else {
                    $values = func_get_args();
                    if (!static::is_logic($values) and isset($values[0]) and is_array($values[0])) {
                        $values = $values[0];
                    }
                }

                $missing = [];
                foreach ($values as $data_key) {
                    $value = static::apply(['var'=>$data_key], $data);
                    if ($value === null or $value === "") {
                        array_push($missing, $data_key);
                    }
                }

                return $missing;
            },
            'missing_some' => function ($minimum, $options) use ($data) {
                $are_missing = static::apply(['missing'=>$options], $data);
                if (count($options) - count($are_missing) >= $minimum) {
                    return [];
                } else {
                    return $are_missing;
                }
            },
            'in' => function ($a, $b) {
                if (is_array($b)) {
                    return in_array($a, $b);
                }
                if (is_string($b)) {
                    return strpos($b, $a) !== false;
                }
                return false;
            },
            'cat' => function () {
                return implode("", func_get_args());
            },
            'max' => function () {
                return max(func_get_args());
            },
            'min' => function () {
                return min(func_get_args());
            },
            '+' => function () {
                if (is_array(func_get_arg(0))) {
                    return array_sum(func_get_arg(0));
                }
                return array_sum(func_get_args());
            },
            '-' => function ($a, $b=null) {
                if ($b===null) {
                    return -$a;
                } else {
                    return $a - $b;
                }
            },
            '/' => function ($a, $b) {
                return $a / $b;
            },
            '*' => function () {
                return array_reduce(func_get_args(), function ($a, $b) {
                    return $a*$b;
                }, 1);
            },
            'merge' => function () {
                return array_reduce(func_get_args(), function ($a, $b) {
                    return array_merge((array)$a, (array)$b);
                }, []);
            },
            'substr' => function () {
                return call_user_func_array('substr', func_get_args());
            },
            'cartesian' => function ($a, $b) {
                $result = array();
                if (is_array($a) && is_array($b)) {
                    foreach ($a as $itemA) {
                        foreach ($a as $itemB) {
                            $res = array_merge([$itemA], [$itemB]);
                            $result[] = $res;
                        }
                    }
                }
                return $result;
            },
            'modify' => function ($a, $b, $c) {
                $properties = explode('.', $b);
                if (is_object($a)) {
                    if (count($properties) > 1) {
                        $data = $a;
                        foreach ($properties as $key => $prop) {
                            if ($key !== array_key_last($properties)) {
                                if (is_object($data) && property_exists($data, $prop)) {
                                    $data = $data->{$prop};
                                } else {
                                    if (array_key_exists($prop, $data)) {
                                        $data = $data[$prop];
                                    }
                                }
                            }
                        }
                        if (is_array($data)) {
                            $newData = [];
                            foreach ($data as $entry) {
                                $entry->{end($properties)} = $c;
                                $newData[] = $entry;
                            }
                        } else {
                            $data->{end($properties)} = $c;
                            $newData = $data;
                        }
                        $a->{reset($properties)} = $newData;
                    } else {
                        $a->{$b} = $c;
                    }
                } else {
                    foreach ($a as $mainkey => $item) {
                        if (count($properties) > 1) {
                            $data = $item;
                            foreach ($properties as $key => $prop) {
                                if ($key !== array_key_last($properties)) {
                                    if (is_object($data) && property_exists($data, $prop)) {
                                        $data = $data->{$prop};
                                    } else {
                                        if (array_key_exists($prop, $data)) {
                                            $data = $data[$prop];
                                        }
                                    }
                                }
                            }
                            if (is_array($data)) {
                                $newData = [];
                                foreach ($data as $entry) {
                                    $entry->{end($properties)} = $c;
                                    $newData[] = $entry;
                                }
                            } else {
                                $data->{end($properties)} = $c;
                                $newData = $data;
                            }
                            $item->{reset($properties)} = $newData;
                        } else {
                            $item->{$b} = $c;
                        }
                    }
                }
                return $a;
            },
            'remove' => function ($a, $b) {
                if (is_object($a)) {
                    unset($a->{$b});
                } else {
                    foreach ($a as $item) {
                        unset($item->{$b});
                    }
                }
                return $a;
            },
            'group' => function ($a, $b) {
                $group[$b] = $a;
                return $group;
            },
            'sqrt' => function($a, $precision = null) {
                if (!is_null($precision) && is_numeric($precision)) {
                    return round(sqrt($a), $precision);
                }
                return sqrt($a);
            },
            'join' => function() use ($data) {
                $joinedArray = [];
                foreach(func_get_args() as $argument) {
                    if (is_array($argument)) {
                        $joinedArray = array_merge($joinedArray, $argument);
                    } else {
                        $joinedArray[] = $argument;
                    }
                }
                return $joinedArray;
            },
            'create' => function() {
                $data = func_get_arg(0);
                if (empty($data)) {
                    $data = [];
                }
                $argumentNumber = 0;
                $object = new \stdClass();
                foreach (func_get_args() as $argument) {
                    $argumentNumber++;
                    if ($argumentNumber == 1) {
                        continue;
                    }

                    // key and value => size = 2
                    if (count($argument) == 2) {
                        $key = $argument[0];
                        $object->$key = $argument[1];
                    }
                }
                array_push($data, $object);
                return $data;
            },
            'delete' => function($a, $b, $c) {
                $result = [];
                foreach ($a as $key => $item) {
                    if (!property_exists($item, $b) || $item->$b != $c) {
                        $result[] = $item;
                    }
                }
                return $result;
            },
            'count' => function($a) {
                if (is_array($a)) {
                    return count($a);
                }
                return 1;
            },
            'slice' => function($array, $offset, $length) {
                return array_slice($array, $offset, $length);
            }
        ];

        //There can be only one operand per logic step
        $op = static::get_operator($logic);
        $values = static::get_values($logic);

        /**
        * Most rules need depth-first recursion. These rules need to manage their
        * own recursion. e.g., if you've added an operator with side-effects
        * you only want `if` to execute the minimum conditions and exactly one
        * consequent.
        */
        if ($op === 'if' || $op == '?:') {
            /* 'if' should be called with a odd number of parameters, 3 or greater
            This works on the pattern:
            if( 0 ){ 1 }else{ 2 };
            if( 0 ){ 1 }else if( 2 ){ 3 }else{ 4 };
            if( 0 ){ 1 }else if( 2 ){ 3 }else if( 4 ){ 5 }else{ 6 };

            The implementation is:
            For pairs of values (0,1 then 2,3 then 4,5 etc)
            If the first evaluates truthy, evaluate and return the second
            If the first evaluates falsy, jump to the next pair (e.g, 0,1 to 2,3)
            given one parameter, evaluate and return it. (it's an Else and all the If/ElseIf were false)
            given 0 parameters, return NULL (not great practice, but there was no Else)
            */
            for ($i = 0 ; $i < count($values) - 1 ; $i += 2) {
                if (static::truthy(static::apply($values[$i], $data, false, $originalData))) {
                    return static::apply($values[$i+1], $data, false, $originalData);
                }
            }
            if (count($values) === $i+1) {
                return static::apply($values[$i], $data, false, $originalData);
            }
            return null;
        } elseif ($op === "filter") {
            $scopedData = static::apply($values[0], $data, false, $originalData);
            $scopedLogic = $values[1];

            if (!$scopedData || !is_array($scopedData)) {
                return [];
            }
            // Return only the elements from the array in the first argument,
            // that return truthy when passed to the logic in the second argument.
            // For parity with JavaScript, reindex the returned array
            return array_values(
                array_filter($scopedData, function ($datum) use ($scopedLogic, $originalData) {
                    return static::truthy(static::apply($scopedLogic, $datum, false, $originalData));
                })
            );
        } elseif ($op === "map") {
            $scopedData = static::apply($values[0], $data, false, $originalData);
            $scopedLogic = $values[1];

            if (!$scopedData || !is_array($scopedData)) {
                return [];
            }

            return array_map(
                function ($datum) use ($scopedLogic, $originalData) {
                    return static::apply($scopedLogic, $datum, false, $originalData);
                },
                $scopedData
            );
        } elseif ($op === "reduce") {
            $scopedData = static::apply($values[0], $data, false, $originalData);
            $scopedLogic = $values[1];
            $initial = isset($values[2]) ? $values[2] : null;

            if (!$scopedData || !is_array($scopedData)) {
                return $initial;
            }

            return array_reduce(
                $scopedData,
                function ($accumulator, $current) use ($scopedLogic, $originalData) {
                    return static::apply(
                        $scopedLogic,
                        ['current'=>$current, 'accumulator'=>$accumulator], false, $originalData
                    );
                },
                $initial
            );
        } elseif ($op === "all") {
            $scopedData = static::apply($values[0], $data);
            $scopedLogic = $values[1];

            if (!$scopedData || !is_array($scopedData)) {
                return false;
            }
            $filtered = array_filter($scopedData, function ($datum) use ($scopedLogic) {
                return static::truthy(static::apply($scopedLogic, $datum));
            });
            return count($filtered) === count($scopedData);
        } elseif ($op === "none") {
            $filtered = static::apply(['filter' => $values], $data);
            return count($filtered) === 0;
        } elseif ($op === "some") {
            $filtered = static::apply(['filter' => $values], $data);
            return count($filtered) > 0;
        }

        if (isset(self::$custom_operations[$op])) {
            $operation = self::$custom_operations[$op];
        } elseif (isset($operators[$op])) {
            $operation = $operators[$op];
        } else {
            throw new \Exception("Unrecognized operator $op");
        }

        //Recursion!
        $values = array_map(function ($value) use ($data, $originalData) {
            return self::apply($value, $data, false, $originalData);
        }, $values);

        return call_user_func_array($operation, $values);
    }

    public static function uses_data($logic)
    {
        if (is_object($logic)) {
            $logic = (array)$logic;
        }
        $collection = [];

        if (self::is_logic($logic)) {
            $op = array_keys($logic)[0];
            $values = (array)$logic[$op];

            if ($op === "var") {
                //This doesn't cover the case where the arg to var is itself a rule.
                $collection[] = $values[0];
            } else {
                //Recursion!
                foreach ($values as $value) {
                    $collection = array_merge($collection, self::uses_data($value));
                }
            }
        }

        return array_unique($collection);
    }


    public static function rule_like($rule, $pattern)
    {
        if (is_string($pattern) and $pattern[0] === '{') {
            $pattern = json_decode($pattern, true);
        }

        //echo "\nIs ". json_encode($rule) . " like " . json_encode($pattern) . "?\n";
        if ($pattern === $rule) {
            return true;
        } //TODO : Deep object equivalency?
        if ($pattern === "@") {
            return true;
        } //Wildcard!
        if ($pattern === "number") {
            return is_numeric($rule);
        }
        if ($pattern === "string") {
            return is_string($rule);
        }
        if ($pattern === "array") {
            return is_array($rule) and ! static::is_logic($rule);
        }

        if (static::is_logic($pattern)) {
            if (static::is_logic($rule)) {
                $pattern_op = static::get_operator($pattern);
                $rule_op = static::get_operator($rule);

                if ($pattern_op === "@" || $pattern_op === $rule_op) {
                    //echo "\nOperators match, go deeper\n";
                    return static::rule_like(
                        static::get_values($rule, false),
                        static::get_values($pattern, false)
                    );
                }
            }
            return false; //$pattern is logic, rule isn't, can't be eq
        }

        if (is_array($pattern)) {
            if (is_array($rule)) {
                if (count($pattern) !== count($rule)) {
                    return false;
                }
                /*
                Note, array order MATTERS, because we're using this array test logic to consider arguments, where order can matter. (e.g., + is commutative, but '-' or 'if' or 'var' are NOT)

                */
                for ($i = 0 ; $i < count($pattern) ; $i += 1) {
                    //If any fail, we fail
                    if (! static::rule_like($rule[$i], $pattern[$i])) {
                        return false;
                    }
                }
                return true; //If they *all* passed, we pass
            } else {
                return false; //Pattern is array, rule isn't
            }
        }

        //Not logic, not array, not a === match for rule.
        return false;
    }

    public static function add_operation($name, $callable)
    {
        self::$custom_operations[$name] = $callable;
    }

}
