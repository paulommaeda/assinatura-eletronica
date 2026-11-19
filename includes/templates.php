<?php
namespace EduSign;
defined('ABSPATH') || exit;

final class Templates {
    const TAGS = ['p'=>[], 'br'=>[], 'strong'=>[], 'b'=>[], 'em'=>[], 'i'=>[], 'u'=>[], 'h2'=>[], 'h3'=>[], 'ul'=>[], 'ol'=>[], 'li'=>[], 'table'=>[], 'thead'=>[], 'tbody'=>[], 'tr'=>[], 'td'=>[], 'th'=>[], 'blockquote'=>[]];
    const VARIABLES = ['instituicao','aluno','ra','responsavel','cpf_mascarado','email','telefone','data_emissao','protocolo'];
    public static function get(int $id): object {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM '.Store::t('templates').' WHERE id=%d', $id));
        Store::require((bool)$row, 'Modelo não encontrado.', 404);
        return $row;
    }
    public static function active(): array {
        global $wpdb;
        return $wpdb->get_results("SELECT id,title,version FROM ".Store::t('templates')." WHERE status='active' ORDER BY title,id DESC");
    }
    public static function save(array $input): int {
        global $wpdb;
        $title = Store::text($input['title'] ?? '', 'nome do modelo');
        $source = $input['html_content'] ?? '';
        Store::require(is_string($source) && strlen($source) <= 150000, 'O corpo do modelo deve ter até 150 KB.');
        if ($source === strip_tags($source)) { $source = wpautop(esc_html($source)); }
        $html = trim(wp_kses($source, self::TAGS));
        Store::require(trim(wp_strip_all_tags($html)) !== '', 'Informe o texto do contrato.');
        $lines = $input['clauses'] ?? [];
        Store::require(is_array($lines) && count($lines) <= 30, 'Configure até 30 declarações de ciência.');
        $clauses = [];
        foreach (array_values($lines) as $i => $line) {
            $clauses['clausula_'.($i+1)] = Store::text($line, 'declaração de ciência', 600);
        }
        $base = (int)($input['base_id'] ?? 0);
        return Store::tx(function () use ($wpdb,$base,$title,$html,$clauses) {
            $family = wp_generate_uuid4();
            $version = 1;
            if ($base) {
                $table = Store::t('templates');
                $old = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d FOR UPDATE", $base));
                Store::require($old && $old->status === 'active', 'O modelo foi atualizado. Abra a versão vigente.', 409);
                $family = $old->family;
                $version = (int)$old->version + 1;
                Store::update('templates', ['status'=>'superseded'], ['id'=>$base]);
            }
            return Store::insert('templates', ['family'=>$family,'title'=>$title,'version'=>$version,'html_content'=>$html,
                'required_clauses_json'=>Store::json($clauses),'status'=>'active','created_at'=>Store::now()]);
        });
    }
    public static function render(string $text, array $values, bool $html = true): string {
        $result = preg_replace_callback('/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/', static function ($match) use ($values,$html) {
            $key = $match[1];
            Store::require(array_key_exists($key,$values) && $values[$key] !== '', "Preencha a variável {{$key}} usada no modelo.");
            return $html ? esc_html($values[$key]) : $values[$key];
        }, $text);
        Store::require(!str_contains($result,'{{') && !str_contains($result,'}}'), 'Verifique a grafia das variáveis do modelo.');
        return $result;
    }
}
