# Guía de Contribución para Simply Code

¡Gracias por tu interés en contribuir a Simply Code! Tu ayuda es fundamental para mejorar este plugin y su comunidad.

## Formas de contribuir

- Reportar errores o problemas
- Sugerir nuevas características
- Mejorar la documentación
- Enviar código (nuevas funciones, correcciones, mejoras)
- Compartir nuevos snippets útiles

---

## Reportar errores

Si encuentras un bug, por favor abre un "Issue" en GitHub e incluye:

1. Pasos para reproducir el problema
2. Comportamiento esperado y comportamiento actual
3. Versión de Simply Code, WordPress y PHP
4. Capturas de pantalla o videos si es posible

---

## Sugerir características

¿Tienes una idea para mejorar el plugin? Abre un "Issue" y describe:

- El problema que resuelve tu propuesta
- Cómo debería funcionar la nueva característica
- Por qué sería útil para otros usuarios

---

## Enviar código (Pull Requests)

1. Haz un fork del repositorio y clónalo en tu equipo.
2. Crea una rama para tu cambio:
   ```bash
   git checkout -b feature/nombre-o-bugfix/descripcion
   ```
3. Realiza tus cambios siguiendo los [estándares de codificación de WordPress](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
4. Prueba tu código antes de hacer commit.
5. Escribe mensajes de commit claros y descriptivos.
6. Sincroniza tu rama con `main` antes de enviar el Pull Request.
7. Abre un Pull Request en GitHub, describe tus cambios y referencia cualquier Issue relacionado.

---

## Contribuir con nuevos snippets

¿Tienes un snippet útil de PHP, JavaScript o CSS? ¡Compártelo con la comunidad!

### ¿Qué tipo de snippets puedes enviar?

- Funciones personalizadas para WordPress
- Hooks y filtros útiles
- Fragmentos de JavaScript para mejorar la experiencia de usuario
- Estilos CSS para personalización rápida
- Soluciones a problemas comunes o utilidades generales

### ¿Cómo enviar tu snippet?

1. **Formato:**
   - Envía tu snippet como un archivo independiente en la carpeta `community-snippets/` (o la que se indique en el repositorio).
   - Usa la extensión adecuada: `.php`, `.js` o `.css`.
   - Incluye al inicio del archivo un bloque de comentarios con:
     ```
     /*
      * Nombre: Nombre descriptivo del snippet
      * Descripción: Explica brevemente qué hace el snippet y en qué casos es útil.
      * Autor: Tu nombre o usuario de GitHub
      * Requisitos: (opcional) Versión mínima de WordPress, plugins necesarios, etc.
      */
     ```
2. **Calidad y seguridad:**
   - El código debe ser seguro, funcional y seguir los [estándares de codificación de WordPress](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/).
   - No incluyas datos sensibles ni dependencias externas no revisadas.
3. **Pull Request:**
   - Haz un fork del repositorio y crea una rama para tu snippet.
   - Sube tu archivo a la carpeta `community-snippets/`.
   - Abre un Pull Request describiendo la utilidad del snippet y cualquier detalle relevante.
4. **Revisión:**
   - El equipo revisará tu snippet para asegurar su calidad y seguridad antes de aceptarlo.
   - Si es aceptado, tu snippet será incluido en la biblioteca de Simply Code y se te dará crédito como autor/a.

#### Ejemplo de snippet enviado

```php
/*
 * Nombre: Desactivar comentarios en medios
 * Descripción: Evita que los usuarios puedan comentar en archivos adjuntos (medios).
 * Autor: @ejemplo
 */
add_filter('comments_open', function($open, $post_id) {
    $post = get_post($post_id);
    if ($post->post_type === 'attachment') {
        return false;
    }
    return $open;
}, 10, 2);
```

---

## Estándares de codificación

Por favor, adhiérete a los [estándares de codificación de WordPress](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) para PHP, JavaScript y CSS. Esto ayuda a mantener la consistencia y legibilidad del código.

---

## Licencia

Al contribuir con código o snippets a Simply Code, aceptas que tu contribución se licenciará bajo la misma [Licencia GPL v2 o posterior](https://www.gnu.org/licenses/gpl-2.0.html) que el proyecto.

---

¡Gracias por ayudar a hacer Simply Code mejor para todos!
