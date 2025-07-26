<div class="wrap">
    <h1><?php echo $edit_mode ? 'Editar Snippet' : 'Nuevo Snippet'; ?></h1>

    <form method="post" id="snippet-form">
        <table class="form-table">
            <tr>
                <th scope="row"><label for="snippet_name">Nombre del Snippet</label></th>
                <td>
                    <input name="snippet_name" type="text" id="snippet_name" 
                           value="<?php echo $edit_mode ? esc_attr($_GET['edit']) : ''; ?>"
                           class="regular-text"
                           <?php echo $edit_mode ? 'readonly' : ''; ?> required>
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

        <?php submit_button($edit_mode ? 'Actualizar Snippet' : 'Guardar Snippet'); ?>
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
</script>
