<div class="wrap">
    <h1><?php echo $edit_mode ? 'Editar Snippet' : 'Nuevo Snippet'; ?></h1>

    <form method="post" action="" id="snippet-form">
        <?php wp_nonce_field('simply_code_actions'); ?>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="snippet_name">Nombre del Snippet</label></th>
                <td>
                    <input type="text"
                           id="snippet_name"
                           name="snippet_name"
                           value="<?php echo $edit_mode && $snippet ? esc_attr($snippet['name']) : ''; ?>"
                           <?php echo $edit_mode ? 'readonly' : ''; ?>
                           class="regular-text"
                           required>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="description">Descripción</label></th>
                <td>
                    <input name="description" type="text" id="description" 
                           value="<?php echo esc_attr($description); ?>" 
                           class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="hook-priorities">Configuración de Hooks</label>
                    <p class="description">Los hooks se detectan automáticamente. Ajusta las prioridades según necesites.</p>
                </th>
                <td>
                    <div id="hook-priorities-container">
                        <div id="detected-hooks">
                            <?php if (!empty($hooks_data)): ?>
                                <?php foreach ($hooks_data as $hook_name => $hook_info): ?>
                                    <div class="hook-priority-item" style="margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;">
                                        <label style="display: flex; align-items: center; gap: 10px;">
                                            <strong><?php echo esc_html($hook_name); ?></strong>
                                            <span class="badge" style="background: <?php echo $hook_info['type'] === 'action' ? '#00a32a' : '#0073aa'; ?>; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                                                <?php echo esc_html($hook_info['type']); ?>
                                            </span>
                                            <input type="number" 
                                                name="hook_priorities[<?php echo esc_attr($hook_name); ?>]" 
                                                value="<?php echo esc_attr($hook_info['priority']); ?>" 
                                                min="1" 
                                                max="9999" 
                                                style="width: 80px;">
                                            <span class="description">Prioridad (menor número = se ejecuta antes)</span>
                                            <?php if (isset($critical_hooks[$hook_name])): ?>
                                                <span style="color: #d63638; font-size: 12px;">⚠️ Hook crítico</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <button type="button" id="refresh-hooks" class="button">🔄 Detectar Hooks</button>
                        <button type="button" id="add-manual-hook" class="button">➕ Agregar Hook Manual</button>
                    </div>
                </td>
            </tr>
            <?php if (!$edit_mode): ?>
            <tr>
                <th scope="row"><label for="template">Plantilla</label></th>
                <td>
                    <select name="template" id="template">
                        <option value="">Seleccionar plantilla...</option>
                        <?php foreach ($templates as $key => $template): ?>
                            <option value="<?php echo esc_attr($key); ?>">
                                <?php echo esc_html($template['description']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <?php endif; ?>
        </table>

        <h2 class="nav-tab-wrapper">
            <a href="#" class="nav-tab nav-tab-active" data-tab="php">PHP</a>
            <a href="#" class="nav-tab" data-tab="js">JavaScript</a>
            <a href="#" class="nav-tab" data-tab="css">CSS</a>
        </h2>

        <div class="tab-content active" id="tab-php">
            <textarea name="php_code" rows="20" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($php_code); ?></textarea>
        </div>

        <div class="tab-content" id="tab-js">
            <textarea name="js_code" rows="20" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($js_code); ?></textarea>
        </div>

        <div class="tab-content" id="tab-css">
            <textarea name="css_code" rows="20" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($css_code); ?></textarea>
        </div>

        <?php submit_button($edit_mode ? 'Actualizar Snippet' : 'Crear Snippet'); ?>
    </form>
</div>

<style>
.tab-content {
    display: none;
    margin-top: 1em;
}
.tab-content.active {
    display: block;
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Manejo de pestañas
    const tabs = document.querySelectorAll('.nav-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            e.preventDefault();
            // Remover clase activa de todas las pestañas y contenidos
            tabs.forEach(t => t.classList.remove('nav-tab-active'));
            document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
            
            // Activar la pestaña seleccionada
            tab.classList.add('nav-tab-active');
            document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
        });
    });

    // Manejo de plantillas
    const templateSelect = document.getElementById('template');
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            if (this.value && confirm('¿Seguro que quieres cargar esta plantilla? Se sobrescribirá el código actual.')) {
                document.getElementById('snippet-form').submit();
            }
        });
    }
});

document.addEventListener('DOMContentLoaded', function() {
    const phpTextarea = document.querySelector('textarea[name="php_code"]');
    const hookContainer = document.getElementById('detected-hooks');
    const refreshButton = document.getElementById('refresh-hooks');
    const addManualButton = document.getElementById('add-manual-hook');
    
    let detectTimeout;
    let criticalHooks = <?php echo json_encode($critical_hooks); ?>;
    
    function detectHooks() {
        const phpCode = phpTextarea.value;
        if (!phpCode.trim()) return;
        
        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'simply_code_detect_hooks',
                php_code: phpCode,
                nonce: '<?php echo wp_create_nonce("simply_code_detect_hooks"); ?>'
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderHooksList(data.data.hooks);
            }
        })
        .catch(error => console.error('Error:', error));
    }
    
    function renderHooksList(hooks) {
        // Obtener prioridades existentes
        const existingInputs = hookContainer.querySelectorAll('input[type="number"]');
        const existingPriorities = {};
        existingInputs.forEach(input => {
            const hookName = input.name.match(/hook_priorities\[([^\]]+)\]/)?.[1];
            if (hookName) {
                existingPriorities[hookName] = input.value;
            }
        });
        
        hookContainer.innerHTML = '';
        
        hooks.forEach(hook => {
            const priority = existingPriorities[hook.name] || hook.priority;
            const isCritical = criticalHooks.hasOwnProperty(hook.name);
            
            const hookDiv = document.createElement('div');
            hookDiv.className = 'hook-priority-item';
            hookDiv.style.cssText = 'margin-bottom: 10px; padding: 10px; border: 1px solid #ddd; border-radius: 4px;';
            
            hookDiv.innerHTML = `
                <label style="display: flex; align-items: center; gap: 10px;">
                    <strong>${hook.name}</strong>
                    <span class="badge" style="background: ${hook.type === 'action' ? '#00a32a' : '#0073aa'}; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px;">
                        ${hook.type}
                    </span>
                    <input type="number" 
                           name="hook_priorities[${hook.name}]" 
                           value="${priority}" 
                           min="1" 
                           max="9999" 
                           style="width: 80px;">
                    <span class="description">Prioridad (menor número = se ejecuta antes)</span>
                    ${isCritical ? '<span style="color: #d63638; font-size: 12px;">⚠️ Hook crítico</span>' : ''}
                    <button type="button" class="button-link remove-hook" data-hook="${hook.name}" style="color: #d63638;">✕</button>
                </label>
            `;
            
            hookContainer.appendChild(hookDiv);
        });
        
        // Agregar event listeners para remover hooks
        hookContainer.querySelectorAll('.remove-hook').forEach(button => {
            button.addEventListener('click', function() {
                this.closest('.hook-priority-item').remove();
            });
        });
    }
    
    function addManualHook() {
        const hookName = prompt('Nombre del hook:');
        if (!hookName) return;
        
        const hookType = confirm('¿Es una acción? (Aceptar = Acción, Cancelar = Filtro)') ? 'action' : 'filter';
        const priority = prompt('Prioridad (1-9999):', '10');
        
        if (!priority || isNaN(priority)) return;
        
        renderHooksList([{
            name: hookName,
            type: hookType,
            priority: parseInt(priority),
            auto_detected: false
        }]);
    }
    
    // Event listeners
    phpTextarea.addEventListener('input', function() {
        clearTimeout(detectTimeout);
        detectTimeout = setTimeout(detectHooks, 1500);
    });
    
    refreshButton.addEventListener('click', detectHooks);
    addManualButton.addEventListener('click', addManualHook);
    
    // Detectar hooks al cargar si hay código PHP
    if (phpTextarea.value.trim()) {
        detectHooks();
    }
});
</script>
