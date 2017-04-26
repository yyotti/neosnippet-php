<?php
if (!defined('PHP_VERSION_ID') or PHP_VERSION_ID < 50600) {
    echo "PHP version is too old.\n";
    exit(1);
}

$options = getopt('o::d::', [ 'output', 'phphelp_dir' ]);
$help_dir = !empty($options['d']) ? rtrim($options['d'], '\/') : '';
if (!is_dir($help_dir)) {
    $help_dir = '';
}
$output_file = !empty($options['o']) ? $options['o'] : rtrim(__DIR__, '/') . '/snippets/php.snip';

$functions = get_available_functions($help_dir);

create_snippet($output_file, $functions);

function is_php7_or_higher() {
    return PHP_VERSION_ID >= 70000;
}

function get_available_functions($help_dir) {
    $functions = array_filter(
        array_map(function ($fname) use ($help_dir) {
            try {
                $function = new ReflectionFunctionEx($fname, $help_dir);
                return ($function->isDeprecated() or $function->isDisabled()) ? null : $function;
            } catch (ReflectionException $e) {
                return null;
            }
        }, get_defined_functions()['internal']),
        function ($f) { return !is_null($f); }
    );

    return $functions;
}

function create_snippet($output, $functions) {
    file_put_contents($output, implode("\n", array_map(function ($func) { return $func->toSnippet(); }, $functions)));
}

class ReflectionFunctionEx extends ReflectionFunction {
    public $description;

    private $parameters;

    private $returnType;

    function __construct($name, $help_dir = '') {
        parent::__construct($name);

        $this->parameters = array_map(
            function ($param) use ($help_dir) { return new ReflectionParameterEx($this->getName(), $param->getName(), $help_dir); },
            parent::getParameters()
        );

        if (!empty($help_dir)) {
            $filename = sprintf('function.%s.html', str_replace('_', '-', $name));
            $help_file = rtrim($help_dir, '/') . "/{$filename}";
            if (is_file($help_file)) {
                $this->loadDescription($help_file);
            }
        }
    }

    public function getParameters() {
        return $this->parameters;
    }

    private function loadDescription($help_file) {
        require_once 'phpQuery-onefile.php';

        $doc = phpQuery::newDocument(file_get_contents($help_file));
        $this->description = trim($doc['.refnamediv .refpurpose .dc-title']->text());

        $method_names = $doc['.refsect1.description .methodsynopsis.dc-description .methodname:not(:contains("::"))'];
        $cnt = $method_names->count();
        if ($cnt <= 0) {
            // No '.methodname'.
            //   ex. date_create (date_create is alias for DateTime::__construct())
            return;
        }

        $required_param_cnt = $this->getNumberOfRequiredParameters();

        $method = null;
        foreach ($method_names as $method_name) {
            $m = trim(preg_replace('/.+\((.*)\)/', '\1', preg_replace('/\[.+\]|void/', '', preg_replace('/[\r\n]/', '', pq($method_name)->parent()->text()))));
            $param_cnt = empty($m) ? 0 : count(explode(',', $m));
            if ($required_param_cnt == $param_cnt) {
                $method = $method_name;
                break;
            }
        }
        if (is_null($method)) {
            // TODO 任意パラメータも含めてもういっかい探ってみる
            print "{$this->getName()}'s description is not found or not unique.\n";
            return;
        }

        $return_type = trim(pq($method)->prev('.type')->text());
        if (!empty($return_type)) {
            $this->description = sprintf('%s %s', $return_type, $this->description);
        }

        $params = $this->getParameters();
        $method_params = pq($method)->nextAll('.methodparam:has(.parameter)');
        for ($i = 0; $i < min($method_params->count(), $this->getNumberOfParameters()); $i++) {
            $p = pq($method_params->get($i));

            $type = trim($p->find('.type:not(a)')->text());
            if (!empty($type)) {
                $this->getParameters()[$i]->setTypeString($type);
            }

            if ($params[$i]->isOptional() && preg_match('/^\s*=\s*(.+)$/', trim($p->find('.initializer')->text()), $m) === 1) {
                $params[$i]->setDefaultValue($m[1]);
            }
        }
    }

    public function getReturnTypeString() {
        if (method_exists($this, 'getReturnType')) {
            return (string) $this->getReturnType();
        }

        return $this->returnType;
    }

    public function toSnippet() {
        $lines[] = "snippet     {$this->name}";
        if (!empty($this->getReturnTypeString()) or !empty($this->description)) {
            $abbr = 'abbr        ';
            if (!empty($this->getReturnTypeString())) {
                $abbr .= "{$this->getReturnTypeString()} ";
            }
            if (!empty($this->description)) {
                $abbr .= "{$this->description}";
            }
            $lines[] = trim($abbr);
        }
        $lines[] = 'options     ' . ((!empty($this->getReturnTypeString()) and $this->getReturnTypeString() == 'void') ? 'head' : 'word');
        $lines[] = "\t{$this->name}(" . $this->params2String() . ')${0}';
        $lines[] = '';

        return implode("\n", $lines);
    }

    private function params2String() {
        $parts = [];

        foreach ($this->parameters as $p) {
            $pos = $p->getPosition() + 1;
            $comma = ($pos > 1 ? ', ' : '');

            $param = $p->toSnippet();
            // TODO オプショナルな場合はデフォルト値が残るようにする
            // TODO オプショナルでない場合は , は外に出るように
            // TODO オプショナルは全体を[]で囲んで、全体を消せるようにしたい([ [int $opt1 = 1], [int $opt2 = 2] ])
            $parts[$pos] = "\${{$pos}:{$comma}{$param}}";
        }
        ksort($parts);

        return implode('', $parts);
    }
}

class ReflectionParameterEx extends ReflectionParameter {
    private $type;

    private $defaultValue;

    function __construct($function, $name) {
        parent::__construct($function, $name);
    }

    public function getTypeString() {
        return !isset($this->type) ? '' : $this->type;
    }

    public function setTypeString($type) {
        $this->type = $type;
    }

    public function setDefaultValue($defaultValue) {
        $this->defaultValue = $defaultValue;
    }

    public function getDefaultValue() {
        return !isset($this->defaultValue) ? '' : $this->defaultValue;
    }

    public function isDefaultValueAvailable() {
        return isset($this->defaultValue);
    }

    public function toSnippet() {
        $type = $this->getTypeString();
        if (empty($type)) {
            if ($this->isArray()) {
                $type = 'array ';
            } elseif ($this->isCallable()) {
                $type = 'callable ';
            }
        } else {
            $type .= ' ';
        }
        $ref_sign = $this->isPassedByReference() ? '&' : '';

        $prefix = '';
        $default_value = '';
        $suffix = '';
        if ($this->isOptional()) {
            $prefix = '[';
            if ($this->isDefaultValueAvailable()) {
                $default_value = ' = ' . $this->getDefaultValue();
            }
            $suffix = ']';
        }
        $variadic = $this->isVariadic() ? '...' : '';

        return trim("{$prefix}{$type}{$ref_sign}\${$this->getName()}{$variadic}{$default_value}{$suffix}");
    }
}
