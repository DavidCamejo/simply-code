<div class="wrap">
    <h1><?= isset($_REQUEST['snippet']) ? 'Editar Snippet' : 'Nuevo Snippet' ?></h1>
    
    <?php if (!isset($_REQUEST['snippet'])): ?>
    <div style="margin: 20px 0;">
        <form method="post" style="display: inline;">
            <label>
                <strong>Usar plantilla:</strong>
                <select name="template" onchange="this.form.submit()">
                    <option value="">Seleccionar plantilla...</option>
                    <?php foreach ($templates as $tpl_name => $tpl): ?>
                    <option value="<?= esc_attr($tpl_name) ?>"><?= esc_html($tpl['description']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
        </form>
    </div>
    <?php endif; ?>
    
    <form method="post">
        <label>
            <strong>Nombre del snippet:</strong><br>
            <input type="text" name="snippet_name" value="<?= isset($_REQUEST['snippet']) ? esc_attr($_REQUEST['snippet']) : '' ?>"
                   <?= isset($_REQUEST['snippet']) ? 'readonly' : 'required' ?> style="width: 30%;">
        </label>
        <br><br>
        <label>
            <strong>Descripción:</strong><br>
            <input type="text" name="description" value="<?= esc_attr($description) ?>" style="width: 60%;">
        </label>
        <div class="sc-editor-tabs">
            <ul>
                <li class="active" data-tab="php">PHP</li>
                <li data-tab="js">JavaScript</li>
                <li data-tab="css">CSS</li>
            </ul>
            <div class="tab-content active" id="tab-php">
                <textarea name="php_code" rows="20" cols="100" style="font-family: monospace;"><?= esc_textarea($php_code) ?></textarea>
            </div>
            <div class="tab-content" id="tab-js">
                <textarea name="js_code" rows="20" cols="100" style="font-family: monospace;"><?= esc_textarea($js_code) ?></textarea>
            </div>
            <div class="tab-content" id="tab-css">
                <textarea name="css_code" rows="20" cols="100" style="font-family: monospace;"><?= esc_textarea($css_code) ?></textarea>
            </div>
        </div>
        <br>
        <input type="submit" class="button button-primary" value="Guardar Snippet">
        <a href="?page=simply-code" class="button">Cancelar</a>
    </form>
</div>

<script src="<?= SC_URL . 'assets/js/editor.js' ?>"></script>
<link rel="stylesheet" href="<?= SC_URL . 'assets/css/editor.css' ?>">