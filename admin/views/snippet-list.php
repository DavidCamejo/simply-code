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
    </style>
    
    <?php
    // Safe mode toggle
    $safe_mode = get_option(Simply_Code_Admin::OPTION_SAFE_MODE, 'on');
    if (isset($_POST['safe_mode_toggle'])) {
        update_option(Simply_Code_Admin::OPTION_SAFE_MODE, 
            $_POST['safe_mode'] === 'on' ? 'on' : 'off'
        );
        echo '<div class="notice notice-success"><p>Configuración de modo seguro actualizada.</p></div>';
    }
    ?>
    
    <div class="card" style="max-width: 100%;">
        <form method="post">
            <label>
                <input type="checkbox" name="safe_mode" value="on" <?php checked($safe_mode, 'on') ?>>
                <strong>Modo seguro</strong> (validar sintaxis PHP antes de guardar y ejecutar snippets)
            </label>
            <p class="description">El modo seguro evita que se guarden o ejecuten snippets con errores de sintaxis, lo que podría romper su sitio.</p>
            <p><input type="submit" name="safe_mode_toggle" class="button button-secondary" value="Guardar configuración"></p>
        </form>
    </div>
    
    <div style="margin: 20px 0;">
        <a href="?page=simply-code-new" class="page-title-action">Nuevo Snippet</a>
    </div>

    <table class="widefat">
        <thead>
            <tr>
                <th>Nombre</th>
                <th>Descripción</th>
                <th>Estado</th>
                <th>Orden</th>
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
                            <button name="move_up" value="<?= $i ?>" class="button" title="Subir">↑</button>
                        </form>
                    <?php endif; ?>
                    <?php if ($i < count($snippets) - 1): ?>
                        <form method="post" style="display:inline;">
                            <button name="move_down" value="<?= $i ?>" class="button" title="Bajar">↓</button>
                        </form>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="?page=simply-code-edit&snippet=<?= urlencode($snippet['name']) ?>" class="button">Editar</a>
                    <a href="?page=simply-code-delete&snippet=<?= urlencode($snippet['name']) ?>" class="button" onclick="return confirm('¿Estás seguro de que quieres eliminar este snippet?')">Eliminar</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>