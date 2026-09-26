import { config } from '@vue/test-utils'
import { defineComponent } from 'vue'

// Component tests mount pages without installing a router. Keep links visible
// and let individual tests override this stub when they inspect route objects.
config.global.components.RouterLink = defineComponent({
  name: 'RouterLink',
  props: ['to'],
  template: '<a class="router-link" :href="typeof to === \'string\' ? to : to.path" :data-to="JSON.stringify(to)"><slot /></a>',
})
