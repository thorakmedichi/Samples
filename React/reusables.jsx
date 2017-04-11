/**
 * FORM COMPONENTS
 * These are the components that can be used within any type of component
 */
var EditableField = React.createClass({

	componentDidUpdate: function() {
		$(this.refs.xeditable).editable({ 
			type: this.props.type,
			url: this.props.url,
			name: this.props.name,
			pk: this.props.name,
			value: (this.props.valueInt ? this.props.valueInt : this.props.value),
			source: this.props.options,
			sourceCache: false,
			disabled: this.props.disabled,

			ajaxOptions: {
				type: 'put',
				dataType: 'json'
			},

			success: function(response, newValue) {
				if (response !== undefined) {
					if(!response.success) return response.message;
				}
			},

			error: function(errors) {
				return 'There was an issue updating the customer. Please contact the Administrator!';
			}
		});
	},

	render: function() {
		return (
			<a href="#" ref="xeditable" title={this.props.title} id={"this.props.name"}>{this.props.value}</a>
		);
	}
});
window.EditableField = EditableField;

var BuildTextarea = React.createClass({
	render: function() {
		return (
			<textarea 
				name={this.props.name} 
				className="form-control expanding" 
				placeholder={this.props.placeholder} 
				rows="2" cols="30" 
				required={this.props.isRequired}
				onKeyUp={this.props.onKeyUp}
				onChange={this.props.onMessageChange}
				disabled={this.props.disabled}
				value={this.props.messageText}>
			</textarea>
		)
	}
});
window.BuildTextarea = BuildTextarea;


/**
 * ALERT COMPONENTS
 * These are the components are additions to panels, forms or pages to return responses to the user
 */
var AlertPanel = React.createClass({
	render: function() {
		var type = this.props.response.type;
		var message = this.props.response.message;

		if (type && message) {
			switch(type) {
				case 'success':
					var className = 'alert-success';
					break;
				case 'error':
					var className = 'alert-danger';
					break;
				case 'info':
					var className = 'alert-info';
					break;
			}

			return (
				<div className="alert-container">
					<div className={"alert send-alert " + className + " fade in"}>
						<button className="close" data-dismiss="alert"><span>&times;</span></button>
						<div className="message">{message}</div>
					</div>
				</div>
			);
		}

		return (
			<div className="alert-container"></div>
		);
	}
});
window.AlertPanel = AlertPanel;


/**
 * PANEL COMPONENTS
 * These are the main components that are necissary for creating a panel
 */
var PanelTab = React.createClass({
	render: function (){
		var active = (this.props.selectedTab ? 'active' : '');

		return (
			<li key={this.props.key} className={active}>
				<a data-toggle="tab" onClick={this.props.onClick} href={this.props.href}>{this.props.type.ucfirst()}</a>
			</li>
		);
	}
});
window.PanelTab = PanelTab;

var PanelHeading = React.createClass({
	handleClick: function(type){
		// Fire function that exists in parent component
		// I need this callback function so that I can update
		// the PanelContent, the sibling of PanelHeading
		// because this state does not propogate down this chain
		this.props.onTabChange(type); 
	},

	render: function(){
		// Loop through each of the tab types and build the html to display the tabs
		return (
			<div className="panel-heading">
				<div className="panel-control">
					<ul className="nav nav-tabs">
						{this.props.tabs.map(function(type, i) {
							var selectedTab = (type === this.props.selectedTab ? true : false);
							return <PanelTab key={i} href={"#tab-" + type} onClick={this.handleClick.bind(this, type)} type={type} selectedTab={selectedTab} />
						}, this )}
					</ul>
				</div>
				<h3 className="panel-title">{this.props.title}</h3>
			</div>
		);
	}
});
window.PanelHeading = PanelHeading;

var PanelContent = React.createClass({
	render: function() {
		// Loop through each of the tabs and output the html 
		// needed to make a list Panel to hold the messages for this group type
		return (
			<div className="panel-body">
				<div className="tab-content">
					{this.props.tabs.map(function(type, i) {
						var active = ((type == this.props.selectedTab) ? 'active in' : '');
						return (
							<div key={i} id={"tab-" + type} className={"tab-pane tab-pane-scrollable fade " + active }>
								{this.props.children}
							</div>
						);
					}, this)}
				</div>
			</div>
		);
	}
});
window.PanelContent = PanelContent;

