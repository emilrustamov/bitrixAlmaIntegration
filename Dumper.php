<?php
class Dumper
{
    public static function dump($data, bool $return = false, bool $html = true)
    {
        $output = self::formatOutput($data, $html);

        if ($return) {
            return $output;
        }

        echo $output;
        return null;
    }

    private static function formatOutput($data, bool $html): string
    {
        ob_start();

        if ($html) {
            echo '<pre style="';
            echo 'background: #1e1e1e;';
            echo 'border: 1px solid #444;';
            echo 'border-left: 4px solid #4CAF50;';
            echo 'color: #e0e0e0;';
            echo 'font-family: "Fira Code", "Consolas", monospace;';
            echo 'font-size: 18px;';
            echo 'line-height: 1.6;';
            echo 'margin: 20px 0;';
            echo 'padding: 15px;';
            echo 'overflow: auto;';
            echo 'border-radius: 4px;';
            echo 'box-shadow: 0 2px 10px rgba(0,0,0,0.2);';
            echo '">';
        }

        self::prettyPrint($data, $html);

        if ($html) {
            echo '</pre>';
        }

        return ob_get_clean();
    }

    private static function prettyPrint($var, bool $html, int $indent = 0)
    {
        $spaces = str_repeat('    ', $indent);

        switch (gettype($var)) {
            case 'array':
                if ($html) echo '<span style="color:#569cd6;">array</span>';
                else echo 'array';
                echo ' (' . count($var) . ") {\n";

                foreach ($var as $key => $value) {
                    echo $spaces . '    ';
                    if ($html) echo '<span style="color:#9cdcfe;">';
                    echo is_int($key) ? $key : "'$key'";
                    if ($html) echo '</span>';
                    echo ' => ';
                    self::prettyPrint($value, $html, $indent + 1);
                }

                echo $spaces . "}\n";
                break;

            case 'object':
                $class = get_class($var);
                if ($html) echo '<span style="color:#569cd6;">object</span>';
                else echo 'object';
                echo " ($class) {\n";

                $vars = get_object_vars($var);
                foreach ($vars as $key => $value) {
                    echo $spaces . '    ';
                    if ($html) echo '<span style="color:#9cdcfe;">';
                    echo $key;
                    if ($html) echo '</span>';
                    echo ' => ';
                    self::prettyPrint($value, $html, $indent + 1);
                }

                echo $spaces . "}\n";
                break;

            case 'string':
                if ($html) echo '<span style="color:#ce9178;">';
                echo "'$var'";
                if ($html) echo '</span>';
                echo ' (' . strlen($var) . ")\n";
                break;

            case 'integer':
                if ($html) echo '<span style="color:#b5cea8;">';
                echo $var;
                if ($html) echo '</span>';
                echo "\n";
                break;

            case 'double':
                if ($html) echo '<span style="color:#b5cea8;">';
                echo $var;
                if ($html) echo '</span>';
                echo "\n";
                break;

            case 'boolean':
                if ($html) echo '<span style="color:#569cd6;">';
                echo $var ? 'true' : 'false';
                if ($html) echo '</span>';
                echo "\n";
                break;

            case 'NULL':
                if ($html) echo '<span style="color:#569cd6;">';
                echo 'null';
                if ($html) echo '</span>';
                echo "\n";
                break;

            default:
                var_dump($var);
                break;
        }
    }

    public static function log($data, string $file = 'debug.log', bool $append = true)
    {
        $mode = $append ? FILE_APPEND : 0;
        $output = "[" . date('Y-m-d H:i:s') . "]\n";
        $output .= self::formatOutput($data, false);
        $output .= "\n---------------------------------\n\n";

        file_put_contents($file, $output, $mode);
    }

    public static function getInfo($var): string
    {
        $type = gettype($var);

        switch ($type) {
            case 'string':
                return "string(" . strlen($var) . ")";
            case 'array':
                return "array(" . count($var) . ")";
            case 'object':
                return "object(" . get_class($var) . ")";
            case 'resource':
                return "resource(" . get_resource_type($var) . ")";
            default:
                return $type;
        }
    }
}