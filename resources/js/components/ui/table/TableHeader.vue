<script setup>
import { cn } from "cn";

const props = defineProps({
  class: {
    type: [Boolean, null, String, Object, Array],
    required: false,
    skipCheck: true,
  },
});
</script>

<template>
  <thead data-slot="table-header" :class="cn('[&_tr]:border-b bg-[#F6F2E4]/70 [&_th]:text-[#002C52] [&_th:first-child]:rounded-tl-xl [&_th:last-child]:rounded-tr-xl', props.class)">
    <slot />
  </thead>
</template>
