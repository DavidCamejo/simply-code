<?php
if (!defined('ABSPATH')) exit;
$title = $edit_mode ? 'Editar Snippet' : 'Nuevo Snippet';
?>
<div class="wrap">
    <h1><?php echo esc_html($title); ?></h1>

    <?php if (!empty($snippet) && $edit_mode): ?>
        <p><strong><?php echo esc_html($snippet['name']); ?></strong></p>
    <?php endif; ?>

    <form id="simply-code-form" method="post" action="">
        <?php wp_nonce_field('simply_code_actions'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><label for="snippet_name">Nombre del Snippet</label></th>
                <td>
                    <input name="snippet_name" id="snippet_name" type="text" value="<?php echo esc_attr($snippet['name'] ?? ''); ?>" class="regular-text" required>
                    <p class="description">Sólo letras, números, guiones y guiones bajos.</p>
                </td>
            </tr>

            <tr>
                <th scope="row"><label for="description">Descripción</label></th>
                <td>
                    <input name="description" id="description" type="text" value="<?php echo esc_attr($description); ?>" class="regular-text">
                </td>
            </tr>

            <?php if (!$edit_mode): ?>
            <tr>
                <th scope="row"><label for="template">Plantilla</label></th>
                <td>
                    <select name="template" id="template">
                        <option value="">Seleccionar plantilla...</option>
                        <?php foreach ($templates as $key => $template): ?>
                            <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($template['description']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php endif; ?>

            <tr>
                <th scope="row">PHP</th>
                <td>
                    <textarea name="php_code" id="php_code" rows="12" cols="80" class="large-text code"><?php echo esc_textarea($php_code); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">JavaScript</th>
                <td>
                    <textarea name="js_code" id="js_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($js_code); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">CSS</th>
                <td>
                    <textarea name="css_code" id="css_code" rows="6" cols="80" class="large-text code"><?php echo esc_textarea($css_code); ?></textarea>
                </td>
            </tr>

            <tr>
                <th scope="row">Hooks detectados</th>
                <td>
                    <div id="sc-hooks-list">
                        <?php if (!empty($hooks_data)): ?>
                            <?php foreach ($hooks_data as $hook_name => $hook_info): ?>
                                <div class="sc-hook-row">
                                    <strong><?php echo esc_html($hook_name); ?></strong>
                                    <span class="description"><?php echo esc_html($hook_info['type'] ?? ''); ?></span>
                                    <br>
                                    Prioridad: <input type="number" name="hook_priorities[<?php echo esc_attr($hook_name); ?>]" value="<?php echo esc_attr($hook_info['priority'] ?? 10); ?>" style="width:80px;">
                                    <?php if (!empty($critical_hooks[$hook_name])): ?>
                                        <span style="color:#d9534f; margin-left:8px;">⚠️ Hook crítico</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <p class="description">Los hooks se detectan automáticamente. Puedes usar el botón para detectarlos.</p>
                        <?php endif; ?>
                    </div>

                    <p>
                        <button id="sc-detect-hooks" type="button" class="button">🔄 Detectar Hooks</button>
                        <span id="sc-detect-hooks-message" style="margin-left:10px;"></span>
                    </p>

                    <input type="hidden" id="sc-detect-nonce" value="<?php echo esc_attr(wp_create_nonce('simply_code_detect_hooks')); ?>">
                    <script>window.simply_code_ajax = window.simply_code_ajax || { ajax_url: "<?php echo esc_js(admin_url('admin-ajax.php')); ?>" };</script>
                </td>
            </tr>
        </table>

        <?php submit_button($edit_mode ? 'Actualizar Snippet' : 'Crear Snippet'); ?>
    </form>
</div>

<!-- Cargar el script del editor si existe; si no, se usará ajaxurl ya definido por WP -->
<?php
// Encolar el script debería hacerse mediante admin_enqueue_scripts; este include es de respaldo
$editor_js = plugins_url('assets/js/editor.js', SC_PATH . 'simply-code.php');
echo '<script src="' . esc_url($editor_js) . '"></script>';
?>
