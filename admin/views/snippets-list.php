<div class="wrap">
    <h1>Simply Code</h1>

    <style>
        /* Toggle Switch Styles */
        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 4px;
            bottom: 4px;
            background-color: white;
            transition: .4s;
        }

        input:checked + .slider {
            background-color: #2271b1;
        }

        input:focus + .slider {
            box-shadow: 0 0 1px #2271b1;
        }

        input:checked + .slider:before {
            transform: translateX(26px);
        }

        .slider.round {
            border-radius: 24px;
        }

        .slider.round:before {
            border-radius: 50%;
        }

        /* Inactive snippet row styling */
        tr.inactive-snippet {
            opacity: 0.6;
            background-color: #f5f5f5;
        }

        /* Delete button styling */
        .delete-button {
            color: #dc3232;
        }

        .delete-button:hover {
            color: #dc3232;
            opacity: 0.8;
        }
    </style>

    <?php
    // Safe mode toggle
    $safe_mode = get_option(Simply_Code_Admin::OPTION_SAFE_MODE, 'on');
    ?>

    <div class="tablenav top">
        <div class="alignleft actions">
            <form method="post" style="display:inline;">
                <?php wp_nonce_field('simply_code_actions'); ?>
                <label>
                    <input type="checkbox" name="safe_mode" <?php echo $safe_mode === 'on' ? 'checked' : ''; ?> onChange="this.form.submit()">
                    Modo Seguro
                </label>
                <input type="hidden" name="safe_mode_toggle" value="1">
            </form>
        </div>
        <div class="alignright">
            <a href="<?php echo admin_url('admin.php?page=simply-code-new'); ?>" class="button button-primary">Nuevo Snippet</a>
        </div>
        <br class="clear">
    </div>

    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Descripción</th>
                <th>Estado</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($snippets as $i => $snippet): ?>
            <tr class="<?= isset($snippet['active']) && !$snippet['active'] ? 'inactive-snippet' : '' ?>">
                <td><?= esc_html($snippet['name']) ?></td>
                <td><?= esc_html($snippet['description']) ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('simply_code_actions'); ?>
                        <input type="hidden" name="snippet_name" value="<?= esc_attr($snippet['name']) ?>">
                        <label class="switch">
                            <input type="checkbox" name="snippet_active" <?= isset($snippet['active']) && $snippet['active'] ? 'checked' : '' ?> onChange="this.form.submit()">
                            <span class="slider round"></span>
                        </label>
                        <input type="hidden" name="toggle_snippet_status" value="1">
                    </form>
                </td>
                <td>
                    <?php if ($i > 0): ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('simply_code_actions'); ?>
                            <input type="hidden" name="move_up" value="<?= $i ?>">
                            <button type="submit" class="button" title="Subir">↑</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($i < count($snippets) - 1): ?>
                        <form method="post" style="display:inline;">
                            <?php wp_nonce_field('simply_code_actions'); ?>
                            <input type="hidden" name="move_down" value="<?= $i ?>">
                            <button type="submit" class="button" title="Bajar">↓</button>
                        </form>
                    <?php endif; ?>

                    <a href="<?php echo add_query_arg(['page' => 'simply-code-new', 'edit' => $snippet['name']], admin_url('admin.php')); ?>" class="button" title="Editar">✎</a>

                    <form method="post" style="display:inline;" onsubmit="return confirm('¿Estás seguro de que deseas eliminar este snippet?');">
                        <?php wp_nonce_field('simply_code_actions'); ?>
                        <input type="hidden" name="snippet_name" value="<?= esc_attr($snippet['name']) ?>">
                        <input type="hidden" name="delete_snippet" value="1">
                        <button type="submit" class="button delete-button" title="Eliminar">🗑</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($snippets)): ?>
            <tr>
                <td colspan="4">No hay snippets disponibles.</td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
// Confirmación adicional para eliminar snippets
document.addEventListener('DOMContentLoaded', function() {
    const deleteForms = document.querySelectorAll('form[onsubmit*="confirm"]');
    deleteForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const snippetName = this.querySelector('input[name="snippet_name"]').value;
            
            if (!confirm(`¿Estás completamente seguro de que quieres eliminar el snippet "${snippetName}"?\n\nEsta acción eliminará permanentemente:\n- El código PHP\n- El código JavaScript\n- El código CSS\n- Todos los metadatos\n\nEsta acción NO se puede deshacer.`)) {
                e.preventDefault();
                return false;
            }
        });
    });
});
</script>
