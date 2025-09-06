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

    public static function dumpLog($logFile = 'log.txt', bool $return = false, bool $html = false)
    {
        if (!file_exists($logFile)) {
            return "Log file not found: $logFile";
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];

        foreach ($lines as $line) {
            $logData = json_decode($line, true);
            if ($logData) {
                $logs[] = $logData;
            }
        }

        return self::dump($logs, $return, $html);
    }

    public static function filterLogs($logFile = 'log.txt', $level = null, $entityType = null, $entityId = null, bool $return = false, bool $html = false)
    {
        if (!file_exists($logFile)) {
            return "Log file not found: $logFile";
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $filteredLogs = [];

        foreach ($lines as $line) {
            $logData = json_decode($line, true);
            if (!$logData) continue;

            $match = true;

            if ($level && $logData['level'] !== $level) {
                $match = false;
            }

            if ($entityType && $logData['entity_type'] !== $entityType) {
                $match = false;
            }

            if ($entityId && $logData['entity_id'] !== $entityId) {
                $match = false;
            }

            if ($match) {
                $filteredLogs[] = $logData;
            }
        }

        return self::dump($filteredLogs, $return, $html);
    }

    public static function getLogStats($logFile = 'log.txt')
    {
        if (!file_exists($logFile)) {
            return "Log file not found: $logFile";
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $stats = [
            'total_entries' => 0,
            'levels' => [],
            'entity_types' => [],
            'entity_ids' => [],
            'time_range' => ['start' => null, 'end' => null]
        ];

        foreach ($lines as $line) {
            $logData = json_decode($line, true);
            if (!$logData) continue;

            $stats['total_entries']++;
            
            // Count levels
            $level = $logData['level'];
            $stats['levels'][$level] = ($stats['levels'][$level] ?? 0) + 1;

            // Count entity types
            if (isset($logData['entity_type'])) {
                $entityType = $logData['entity_type'];
                $stats['entity_types'][$entityType] = ($stats['entity_types'][$entityType] ?? 0) + 1;
            }

            // Count entity IDs
            if (isset($logData['entity_id'])) {
                $entityId = $logData['entity_id'];
                $stats['entity_ids'][$entityId] = ($stats['entity_ids'][$entityId] ?? 0) + 1;
            }

            // Track time range
            $timestamp = $logData['timestamp'];
            if (!$stats['time_range']['start'] || $timestamp < $stats['time_range']['start']) {
                $stats['time_range']['start'] = $timestamp;
            }
            if (!$stats['time_range']['end'] || $timestamp > $stats['time_range']['end']) {
                $stats['time_range']['end'] = $timestamp;
            }
        }

        return $stats;
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
            echo 'font-size: 14px;';
            echo 'line-height: 1.4;';
            echo 'margin: 20px 0;';
            echo 'padding: 15px;';
            echo 'overflow: auto;';
            echo 'border-radius: 4px;';
            echo 'box-shadow: 0 2px 10px rgba(0,0,0,0.2);';
            echo 'max-height: 600px;';
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
                // Special handling for log entries
                if (self::isLogEntry($var)) {
                    self::printLogEntry($var, $html, $indent);
                    return;
                }

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

    private static function isLogEntry($var): bool
    {
        return is_array($var) && 
               isset($var['timestamp']) && 
               isset($var['level']) && 
               isset($var['message']);
    }

    private static function printLogEntry($log, bool $html, int $indent = 0)
    {
        $spaces = str_repeat('    ', $indent);
        
        if ($html) {
            echo '<div style="margin: 15px 0; padding: 15px; border-left: 4px solid ' . self::getLevelColor($log['level']) . '; background: rgba(255,255,255,0.03); border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">';
        }

        // Header with timestamp and level
        echo $spaces;
        if ($html) {
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">';
            echo '<span style="color:#808080; font-size: 12px; font-family: monospace;">[' . $log['timestamp'] . ']</span>';
            echo '<span style="color:' . self::getLevelColor($log['level']) . '; font-weight: bold; font-size: 14px; padding: 2px 8px; border-radius: 3px; background: rgba(255,255,255,0.1);">';
            echo strtoupper($log['level']);
            echo '</span>';
            echo '</div>';
        } else {
            echo '[' . $log['timestamp'] . '] [' . strtoupper($log['level']) . ']';
            echo "\n";
        }

        // Message
        echo $spaces;
        if ($html) {
            echo '<div style="color:#e0e0e0; font-size: 14px; line-height: 1.4; margin-bottom: 8px;">';
            echo htmlspecialchars($log['message']);
            echo '</div>';
        } else {
            echo $log['message'];
            echo "\n";
        }

        // Entity info
        if (isset($log['entity_type']) || isset($log['entity_id'])) {
            echo $spaces;
            if ($html) {
                echo '<div style="color:#9cdcfe; font-size: 12px; margin-bottom: 8px;">';
                echo '📋 Entity: ';
                if (isset($log['entity_type'])) echo '<strong>' . $log['entity_type'] . '</strong>';
                if (isset($log['entity_id'])) echo ' <span style="color:#b5cea8;">#' . $log['entity_id'] . '</span>';
                echo '</div>';
            } else {
                echo 'Entity: ';
                if (isset($log['entity_type'])) echo $log['entity_type'];
                if (isset($log['entity_id'])) echo ' #' . $log['entity_id'];
                echo "\n";
            }
        }

        // Context
        if (isset($log['context']) && !empty($log['context'])) {
            echo $spaces;
            if ($html) {
                echo '<details style="margin-top: 8px;">';
                echo '<summary style="color:#569cd6; cursor: pointer; font-size: 12px; margin-bottom: 8px;">📄 Context (click to expand)</summary>';
                echo '<div style="background: rgba(0,0,0,0.2); padding: 10px; border-radius: 3px; margin-top: 5px;">';
                self::prettyPrintContext($log['context'], $html, $indent + 1);
                echo '</div>';
                echo '</details>';
            } else {
                echo "Context:\n";
                self::prettyPrintContext($log['context'], $html, $indent + 1);
            }
        }

        if ($html) {
            echo '</div>';
        }
    }

    private static function prettyPrintContext($var, bool $html, int $indent = 0)
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
                    self::prettyPrintContext($value, $html, $indent + 1);
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
                    self::prettyPrintContext($value, $html, $indent + 1);
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

    private static function getLevelColor($level): string
    {
        switch (strtoupper($level)) {
            case 'ERROR':
                return '#f44747';
            case 'WARNING':
                return '#ffcc02';
            case 'INFO':
                return '#4ec9b0';
            case 'DEBUG':
                return '#9cdcfe';
            default:
                return '#808080';
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

    public static function getLogErrors($logFile = 'log.txt', bool $return = false, bool $html = false)
    {
        return self::filterLogs($logFile, 'ERROR', null, null, $return, $html);
    }

    public static function getLogWarnings($logFile = 'log.txt', bool $return = false, bool $html = false)
    {
        return self::filterLogs($logFile, 'WARNING', null, null, $return, $html);
    }

    public static function getLogByEntity($logFile = 'log.txt', $entityType, $entityId = null, bool $return = false, bool $html = false)
    {
        return self::filterLogs($logFile, null, $entityType, $entityId, $return, $html);
    }

    public static function searchLogs($logFile = 'log.txt', $searchTerm, bool $return = false, bool $html = false)
    {
        if (!file_exists($logFile)) {
            return "Log file not found: $logFile";
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $matchingLogs = [];

        foreach ($lines as $line) {
            if (stripos($line, $searchTerm) !== false) {
                $logData = json_decode($line, true);
                if ($logData) {
                    $matchingLogs[] = $logData;
                }
            }
        }

        return self::dump($matchingLogs, $return, $html);
    }

    public static function getLogSummary($logFile = 'log.txt')
    {
        $stats = self::getLogStats($logFile);
        
        if (is_string($stats)) {
            return $stats;
        }

        $summary = "=== LOG SUMMARY ===\n";
        $summary .= "Total entries: " . $stats['total_entries'] . "\n";
        $summary .= "Time range: " . $stats['time_range']['start'] . " - " . $stats['time_range']['end'] . "\n\n";
        
        $summary .= "Levels:\n";
        foreach ($stats['levels'] as $level => $count) {
            $summary .= "  $level: $count\n";
        }
        
        if (!empty($stats['entity_types'])) {
            $summary .= "\nEntity Types:\n";
            foreach ($stats['entity_types'] as $type => $count) {
                $summary .= "  $type: $count\n";
            }
        }

        return $summary;
    }

    public static function getLogTable($logFile = 'log.txt', $limit = 50, bool $return = false, bool $html = false)
    {
        if (!file_exists($logFile)) {
            return "Log file not found: $logFile";
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $logs = [];

        foreach (array_slice($lines, -$limit) as $line) {
            $logData = json_decode($line, true);
            if ($logData) {
                $logs[] = $logData;
            }
        }

        if ($html) {
            $output = '<div style="background: #1e1e1e; padding: 20px; border-radius: 8px; margin: 20px 0;">';
            $output .= '<h3 style="color: #e0e0e0; margin-bottom: 20px;">📋 Log Table (Last ' . count($logs) . ' entries)</h3>';
            $output .= '<table style="width: 100%; border-collapse: collapse; color: #e0e0e0; font-family: monospace; font-size: 12px;">';
            $output .= '<thead>';
            $output .= '<tr style="background: rgba(255,255,255,0.1);">';
            $output .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #444;">Time</th>';
            $output .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #444;">Level</th>';
            $output .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #444;">Message</th>';
            $output .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #444;">Entity</th>';
            $output .= '</tr>';
            $output .= '</thead>';
            $output .= '<tbody>';

            foreach ($logs as $log) {
                $levelColor = self::getLevelColor($log['level']);
                $entityInfo = '';
                if (isset($log['entity_type'])) {
                    $entityInfo = $log['entity_type'];
                    if (isset($log['entity_id'])) {
                        $entityInfo .= ' #' . $log['entity_id'];
                    }
                }

                $output .= '<tr style="border-bottom: 1px solid #333;">';
                $output .= '<td style="padding: 8px; color: #808080;">' . $log['timestamp'] . '</td>';
                $output .= '<td style="padding: 8px;"><span style="color: ' . $levelColor . '; font-weight: bold;">' . strtoupper($log['level']) . '</span></td>';
                $output .= '<td style="padding: 8px; max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" title="' . htmlspecialchars($log['message']) . '">' . htmlspecialchars(substr($log['message'], 0, 100)) . (strlen($log['message']) > 100 ? '...' : '') . '</td>';
                $output .= '<td style="padding: 8px; color: #9cdcfe;">' . htmlspecialchars($entityInfo) . '</td>';
                $output .= '</tr>';
            }

            $output .= '</tbody>';
            $output .= '</table>';
            $output .= '</div>';

            if ($return) {
                return $output;
            } else {
                echo $output;
                return null;
            }
        } else {
            // Plain text table
            $output = "=== LOG TABLE (Last " . count($logs) . " entries) ===\n";
            $output .= str_pad("Time", 20) . str_pad("Level", 8) . str_pad("Message", 50) . "Entity\n";
            $output .= str_repeat("-", 100) . "\n";

            foreach ($logs as $log) {
                $entityInfo = '';
                if (isset($log['entity_type'])) {
                    $entityInfo = $log['entity_type'];
                    if (isset($log['entity_id'])) {
                        $entityInfo .= ' #' . $log['entity_id'];
                    }
                }

                $output .= str_pad($log['timestamp'], 20) . 
                          str_pad(strtoupper($log['level']), 8) . 
                          str_pad(substr($log['message'], 0, 47) . (strlen($log['message']) > 47 ? '...' : ''), 50) . 
                          $entityInfo . "\n";
            }

            if ($return) {
                return $output;
            } else {
                echo $output;
                return null;
            }
        }
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