<template>
  <cdx-typeahead-search
    id="searchform"
    ref="searchForm"
    :client="getClient"
    :domain="domain"
    :footer-search-text="$i18n('searchsuggest-containing').text()"
    :suggestions-label="$i18n('searchresults').text()"
    :accesskey="searchAccessKey"
    :title="searchTitle"
    :placeholder="searchPlaceholder"
    :aria-label="searchPlaceholder"
    :initial-input-value="searchQuery"
    :button-label="$i18n('searchbutton').text()"
    :form-action="action"
    :search-language="language"
    :show-thumbnail="showThumbnail"
    :show-description="showDescription"
  >
    <input type="hidden" name="title" value="Special:Search" />
  </cdx-typeahead-search>
</template>

<script>
/* global SubmitEvent */
const codex = require("mediawiki.codex.typeaheadSearch");
module.exports = {
  name: "App",
  components: codex,
  mounted: function () {
    // access the element associated with the cdx-typeahead-search component
    // eslint-disable-next-line no-jquery/variable-pattern
    var SearchForm = this.$refs.searchForm.$el;
    if (this.autofocusInput) {
      // TODO: The cdx-typeahead-search component accepts an id prop but does not
      // display that value as an HTML attribute on the form element.
      SearchForm.querySelector("form").setAttribute("id", "searchform");
      // TODO: The cdx-typeahead-search component does not accept an autofocus parameter
      // or directive. This can be removed when its does.
      SearchForm.querySelector("input").focus();
    }
  },
  computed: {
    /**
     * Allow wikis to replace the default search API client
     *
     * @return {void|Object}
     */
    getClient: function () {
      return mw.config.get("wgSnapwikiskinSearchClient", undefined);
    },
    language: function () {
      return mw.config.get("wgUserLanguage");
    },
    domain: function () {
      // It might be helpful to allow this to be configurable in future.
      return mw.config.get("wgSnapwikiskinSearchHost", location.host);
    },
  },
  props: {
    autofocusInput: {
      type: Boolean,
      default: false,
    },
    action: {
      type: String,
      default: "",
    },
    /** The keyboard shortcut to focus search. */
    searchAccessKey: {
      type: String,
    },
    /** The access key informational tip for search. */
    searchTitle: {
      type: String,
    },
    /** The ghost text shown when no search query is entered. */
    searchPlaceholder: {
      type: String,
    },
    /**
     * The search query string taken from the server-side rendered input immediately before
     * client render.
     */
    searchQuery: {
      type: String,
    },
    showThumbnail: {
      type: Boolean,
      default: true,
    },
    showDescription: {
      type: Boolean,
      default: true,
    },
  },
  data: function () {
    return {
      
    };
  },
  methods: {
    /**
     * @param {SubmitEvent} event
     */
    onSubmit: function (event) {},
  },
};
</script>
